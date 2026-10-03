# Design

## Context

Мотивация и границы описаны в `proposal.md`, наблюдаемое поведение — в трёх capability specs. Проект является минимальным Laravel 13 skeleton: PostgreSQL, Livewire и Docker Compose ещё предстоит настроить. Внешними интеграциями MVP являются только Telegram Bot API и выбранный LLM provider.

Критические ограничения: входящее сообщение нельзя потерять при сбое LLM; недетерминированный LLM-ответ нельзя применять без валидации; повторные Telegram updates и queue retries не должны создавать повторные side effects; персональные данные акции недоступны.

## Goals / Non-Goals

**Goals:**

- Сохранить входящий Telegram update и сообщение до любой AI-обработки.
- Разделить Telegram transport, LLM integration, application orchestration и persistence.
- Обеспечить идемпотентные решения, эскалацию, delivery tracking и lifecycle ticket.
- Сделать каждый важный failure path воспроизводимым в Pest без реальных внешних API.
- Запустить app, PostgreSQL и database queue worker одной командой Docker Compose.

**Non-Goals:**

- Exactly-once доставка на стороне Telegram при сетевом timeout после фактического принятия запроса API.
- Интеграция с промо-системами или автоматическое выполнение административных действий.
- Универсальная PII/DLP-платформа; применяется узкая sanitization для данных, встречающихся в evaluation dataset.
- Горизонтальное масштабирование, отдельный message broker, WebSocket UI или выделенный analytics storage.

## Decisions

### 1. High-level components

```text
+----------------+       +----------------------+       +----------------+
| Telegram Bot   | ----> | Telegram HTTP        | ----> | PostgreSQL     |
| API            | <---- | adapter              |       | updates/messages|
+----------------+       +----------+-----------+       +-------+--------+
                                  | after commit                  |
                                  v                               v
                       +----------+-----------+       +-----------+--------+
                       | Laravel database     | ----> | AI processing job |
                       | queue + worker       |       +-----------+--------+
                       +----------+-----------+                   |
                                  |                               v
                                  |                    +----------+---------+
                                  |                    | LLM adapter        |
                                  |                    +----------+---------+
                                  |                               |
                                  v                               v
                       +----------+-----------+       +-----------+--------+
                       | Delivery / auto-close|       | Decision + ticket |
                       | jobs                 |       | application logic |
                       +----------+-----------+       +-----------+--------+
                                  |                               |
                                  +---------------+---------------+
                                                  v
                                       +----------+-----------+
                                       | Livewire operator UI |
                                       +----------------------+
```

Laravel controllers принимают и валидируют transport input, но не содержат routing или lifecycle logic. Application services выполняют use cases; Eloquent models хранят состояние; adapters инкапсулируют Telegram и LLM HTTP contracts.

Альтернатива с микросервисами или отдельными event consumers отклонена: один Laravel process и queue worker покрывают MVP и проще запускаются через Docker Compose.

### 2. Inbound request flow и transaction boundary

```text
Telegram update
  -> BEGIN
  -> INSERT telegram_updates ON UNIQUE(update_id)
  -> upsert participant
  -> detect and redact sensitive values in memory
  -> discard raw body and INSERT only redacted inbound message + safe metadata
  -> if redaction occurred: create pending safety notification
  -> if active ticket exists: attach message to ticket
  -> continue conversation: reopen resolved and cancel pending resolve intent
  -> if no active ticket exists: insert AI job into the same PostgreSQL transaction
  -> COMMIT; workers can now see the queued work
```

Конфликт уникального `telegram_updates.update_id` означает уже принятый update и завершается успешным webhook response без side effects. Для сообщения при активном ticket AI job не создаётся.

Database jobs создаются с `beforeCommit()` в той же PostgreSQL connection и транзакции, что message и lifecycle state: rollback отменяет обе записи, workers видят работу только после commit. Вызов LLM внутри webhook отклонён, потому что увеличивает latency и теряет сообщение при timeout до persistence.

### 3. PostgreSQL ER model

```text
users (operators)
  id PK

telegram_participants
  id PK
  telegram_user_id UNIQUE
  chat_id

telegram_updates
  id PK
  update_id UNIQUE
  participant_id FK -> telegram_participants.id NULL
  kind
  received_at

tickets
  id PK / public number
  participant_id FK -> telegram_participants.id
  status: open | resolved | closed
  escalation_reason
  first_operator_replied_at NULL
  resolved_since NULL
  closed_at NULL
  close_reason NULL
  created_at

messages
  id PK
  participant_id FK -> telegram_participants.id
  ticket_id FK -> tickets.id NULL
  telegram_update_id FK -> telegram_updates.id NULL
  operator_id FK -> users.id NULL
  direction: inbound | outbound
  author: participant | bot | operator | system
  body (redacted application text)
  redaction_types JSONB NULL
  delivery_status: pending | sent | failed | NULL
  telegram_message_id NULL
  delivered_at NULL
  delivery_attempts
  resolves_ticket (explicit operator intent, default false)
  last_delivery_error NULL
  created_at

support_decisions
  id PK
  message_id FK -> messages.id UNIQUE
  type: answer | escalate | mixed | refuse
  reason NULL
  answer_text NULL
  knowledge_source_hash NULL
  structured_output JSONB
  created_at

jobs / failed_jobs
  Laravel database queue tables
```

Связи: participant имеет много updates, messages и tickets; ticket имеет много messages; inbound message имеет не более одного decision. Частичный уникальный индекс PostgreSQL на `tickets(participant_id) WHERE status IN ('open', 'resolved')` обеспечивает одно незакрытое обращение даже при конкурентной обработке.

Отдельная events/audit таблица не вводится: timestamps, decision и message delivery state достаточны для обязательных метрик и диагностики MVP. Event sourcing отклонён как несоразмерный.

### 4. AI job, retry и идемпотентность решения

`ProcessIncomingMessage` получает только message ID. Перед LLM-вызовом job проверяет существование `support_decisions.message_id`; сохранённое решение завершает job без повторного вызова.

Один основной LLM-вызов выполняется вне длинной DB-транзакции. Модель одновременно понимает все запросы участника, выбирает `decision`/`reason`, формирует конкретный `answer` и приводит `evidence`. PHP выполняет только deterministic validation и получает `ValidatedSupportDecision`, без отдельного decision builder или LLM verifier. После этого короткая транзакция блокирует participant, активный ticket и message (в таком порядке), повторно проверяет отсутствие decision. Если за время LLM-вызова появился активный ticket, сообщение прикрепляется к нему, resolved возвращается в open через TicketLifecycleService, а устаревшее решение и ответ не сохраняются. Если ticket нет, транзакция сохраняет final decision и безопасный structured result, затем создаёт ровно необходимые side effects:

- `answer`: одно исходящее bot message;
- `escalate`: один ticket и уведомление;
- `mixed`: один ticket, grounded answer и уведомление;
- `refuse`: одно исходящее bot message без административного действия.

Unique decision constraint и частичный ticket index являются последней защитой от конкурентных попыток. Исходящие сообщения получают стабильную связь с decision/use case, позволяющую запретить повторное создание на уровне DB constraint или идемпотентного application lookup.

Job имеет от 1 до 3 attempts, timeout и backoff array; `LLM_MAX_ATTEMPTS` ограничивается сверху тремя, даже если environment или worker CLI задают больше. Transient transport/5xx/429/timeout приводит к retry того же основного запроса. LlmRequestException с retryable=false сразу вызывает тот же идемпотентный llm_failure fallback без повторного запроса. Невалидный JSON/schema/evidence сразу вызывает этот fallback и завершает job как failed, без повторной генерации. После исчерпания attempts failure handler выполняет короткую идемпотентную транзакцию: если decision уже существует — ничего не делает; иначе сохраняет escalation decision с `llm_failure`, находит или создаёт ticket и создаёт единственное уведомление.

Внутренний HTTP retry LLM adapter отсутствует. Queue retry является единственной политикой: один LLM HTTP request на попытку, максимум 3 на participant message в штатной обработке. Второго типа запроса generation + verification нет.

Альтернатива `ShouldBeUnique` может быть дополнительным guard, но не заменяет DB constraints: cache uniqueness не является источником истины, а выбранный MVP не требует отдельного Redis cache.

### 5. LLM adapter boundary

Application contract принимает redacted participant text и актуальный текст `promo-rules.md`, возвращая `ValidatedSupportDecision`. Provider adapter выполняет один HTTP POST с authentication и explicit connect/response timeout. Поля результата: `decision`, `reason`, `answer`, `evidence: [{rule_id, quote}]`. Ответ сохраняется и отправляется как пользовательский текст; PHP не подменяет его полным текстом правила.

`LlmDecisionValidator` разрешает только четыре обязательных поля и следующие пары: `answer/rule_answer`, `mixed/mixed_request`, `escalate/participant_specific`, `escalate/not_in_rules`, `refuse/prompt_injection`. `llm_failure` зарезервирован для fallback приложения. `answer`/`mixed` требуют непустые answer и evidence; `escalate` требует null answer и пустой evidence; `refuse` требует безопасный ответ «Я не могу выполнить этот запрос.» и пустой evidence, чтобы не допустить promotional facts под видом отказа. Application layer сохраняет прежние side effects выбранного решения; модель не имеет tools для изменения ticket, победителей, чеков или аккаунтов.

Provider payload содержит два сообщения: `system` включает application prompt, contract, promotion rules и текущее время в `Europe/Moscow`; `user` содержит sanitized participant text. Runtime participant text никогда не интерполируется в system prompt. Для относительных дат и расчётов модель должна возвращать конкретный результат с учётом trusted времени и приведённых правил.

`PromotionRules` строит каталог numbered clauses, сохраняя multiline списки. Validator проверяет точный numeric ID и принадлежность непустой цитаты именно этому пункту, нормализуя только whitespace. В provider JSON Schema режиме задаётся одна схема `support_decision` с четырьмя полями и строгой структурой evidence. `LLM_RESPONSE_FORMAT` сохранён для совместимости JSON mode/text/LM Studio; verifier-specific schema, prompt selector и общий двухфазный deadline удалены.

После ответа модели выполняются только deterministic checks JSON/schema, decision/reason, rule IDs и quote membership. Отдельного semantic verifier нет. Наличие настоящей цитаты подтверждает источник, но само по себе не доказывает семантическое соответствие каждого утверждения свободного answer; качество модели оценивается отдельно. Невалидный result не сохраняется и не отправляется как factual answer: используется существующая safe escalation с `llm_failure`.

Prompt-файлы версионируются отдельно в репозитории. В decision сохраняется структурированный результат и hash factual source для воспроизводимости, но не полный system prompt. Vector database отклонена: один небольшой документ правил помещается в prompt и является единственным knowledge source.

### 6. Telegram adapter и delivery tracking

Inbound webhook проверяет `X-Telegram-Bot-Api-Secret-Token` через `hash_equals` с непустым `config('telegram.webhook_secret')` до parsing и side effects. Missing/wrong token или отсутствующая конфигурация дают HTTP 403. CSRF exception сохраняется; legacy callbacks считаются unsupported после аутентификации webhook.

Parser отличает распознаваемые non-text сообщения участника в private chat от служебных событий и group updates. Для non-text DTO содержит только транспортные IDs, без caption и file metadata. Ingestion сохраняет update metadata и создаёт text-only fallback через существующий `createPendingMessage`: вопрос и текст подписи нужно отправить отдельным текстовым сообщением. Уведомление и delivery job сохраняются в той же transaction; duplicate update не создаёт повтор, существующий notice limiter допускает одно уведомление в минуту. LLM не вызывается, его quota не расходуется; ticket не создаётся и при active ticket продолжает переписку и возвращает resolved в open. При наличии active ticket уведомление относится к его истории. Файлы и подписи не принимаются как вопросы в этом MVP.

Telegram adapter отправляет текст с optional `ReplyKeyboardMarkup`. Inline buttons и callback acknowledgement удалены. Он задаёт explicit timeout и переводит API/network errors в типизированные ошибки.

Лимит текста централизован в `TelegramOutboundMessage::MaxTextLength` (4096 Unicode символов); длина и обрезка используют `mb_*`. Operator reply до persistence проверяется под ticket lock с учётом реального заголовка и короткой redacted quote из того же presentation builder. Превышение бюджета даёт validation error без создания Message/job. Operator reply не обрезается и не разбивается: reply keyboard отправляется с единственным ответом. Для bot/system сообщений, включая grounded LLM ответы, presentation обрезает слишком длинный текст с пометкой `… [сообщение сокращено]`; полный sanitized body сохраняется в истории. Delivery job и Telegram adapter дополнительно отклоняют oversized payload до API-вызова с безопасной non-retryable ошибкой `telegram_message_too_long`. Такая ошибка сохраняет сообщение как `failed` и не переводит ticket в `resolved`.

Каждый исходящий ответ сначала сохраняется как `pending`; затем `DeliverTelegramMessage` отправляет его после commit. При успехе message становится `sent`, сохраняются Telegram message ID и `delivered_at`. После окончательного сбоя message становится `failed`, сохраняется безопасный error code, но body не удаляется.

Создание operator reply выполняется под ticket row lock. Обычный ответ остаётся в open, «Отправить и решить» сохраняет resolves_ticket=true в pending message. Неограниченное число pending/failed replies допустимо; retry/cancellation относятся к конкретному message. Новый ответ оператора или participant input вызывает общий reopen: resolved возвращается в open, resolved_since очищается, resolves_ticket у ещё не доставленных operator messages сбрасывается. Неудачная validation или rollback не отменяет предыдущее намерение.

После successful delivery финализация под ticket/message locks читает актуальный resolves_ticket из DB. Только true и open разрешают resolve и атомарную постановку прежнего delayed auto-close. Во время HTTP ticket остаётся open; participant input, late AI attach или следующий operator reply отменяют intent, поэтому запоздалая доставка не решает продолженную переписку. Revision counters, snapshots и delivery markers не используются. First response timestamp фиксируется один раз по delivered_at независимо от действия «решить».

Ручное закрытие разрешено при pending/failed replies и отменяет все недоставленные outbound messages. Delivery job перед HTTP не отправляет сообщения closed ticket; already-sent jobs идемпотентны. Если Telegram-запрос уже начался перед закрытием, его результат сохраняется как sent, но ticket остаётся closed и новый таймер не создаётся.

Telegram `sendMessage` не предоставляет application idempotency key. При timeout после фактического принятия Telegram API остаётся небольшой риск повторной доставки при retry; он документируется, поскольку устранение потребовало бы внешнего reconciliation, отсутствующего в MVP.

### 7. Ticket state machine

```text
open -- successful delivery of explicit Send and resolve --> resolved
resolved -- participant message or new operator reply -----> open
resolved -- matching delayed auto-close -------------------> closed
open/resolved -- operator close ---------------------------> closed
```

Обычный operator reply статус не меняет. Только явное действие «Отправить и решить» разрешает переход open -> resolved после successful delivery. `closed` terminal: новый вопрос обрабатывается прежним routing flow, тексты старой клавиатуры вне active ticket игнорируются. Новые close reasons — auto_closed и operator_closed; исторический user_confirmed остаётся доступен для чтения.

AutoCloseTicket сохраняет прежние проверки: ticket ID, resolved_since и ID delivered resolving message. Под row lock он закрывает только соответствующий resolved цикл; старые jobs после reopen/manual close/new resolution являются no-op, включая совпадающие timestamps. Legacy payload без resolvedSince не применяется. TICKET_AUTO_CLOSE_HOURS остаётся configurable, default 24.

### 8. Reply keyboard и текстовый feedback

ReplyKeyboardMarkup с «Проблема решена» и «Не решило» сохраняется. Feedback поступает обычным private message, доступен оператору в истории и не закрывает обращение. Любой participant text, включая feedback и /start, прикрепляется к собственному active ticket и возвращает resolved в open; ещё не выполненное resolve intent отменяется. /start сохраняет обычное safety warning. Распознаваемый non-text private message также отменяет решение и возвращает ticket в open, создавая прежний text-only fallback; caption/file metadata не сохраняются.

Unique update ID предотвращает повторные side effects. Legacy inline callbacks по-прежнему игнорируются без persistence или Telegram acknowledgement.

### 9. Operator UI и authentication

Панель использует обычную Laravel session authentication и одну заранее созданную operator account. Все operator routes защищены `auth`; self-registration отсутствует.

На clean start Compose требует `OPERATOR_EMAIL` и `OPERATOR_PASSWORD`, ожидает PostgreSQL healthcheck, затем app последовательно выполняет migrations и `db:seed --force --no-interaction` до запуска HTTP server. Queue worker ждёт app healthcheck. Credentials читаются seeder через environment-backed config; непустой пароль и valid email обязательны, development fallback отсутствует. `firstOrCreate` по unique email обеспечивает идемпотентность для того же email; пароль хранится через `User` hashed cast. Повторный startup не обновляет существующий пароль. `.env.example` содержит только development email и пустой пароль; реальные credentials задаются локально.

Livewire отображает очередь, историю, форму ответа, delivery state, ручное закрытие и три метрики. Очередь по умолчанию показывает `open` и `resolved`; фильтры также позволяют просмотреть `closed` или все обращения, сортируя их от новых к старым. Закрытые обращения read-only. Достаточно server-driven navigation и refresh/polling; WebSockets и SPA отклонены. User-provided text выводится только через escaped Blade syntax, без raw HTML.

История выбирается через `selectedTicket.messages()` и содержит только записи с `ticket_id` выбранного обращения. Предыдущие и последующие tickets одного participant, включая closed, имеют независимые истории. Unticketed pre-escalation context временно не показывается: безопасная граница контекста отдельно не определяется. Cursor pagination сохраняет страницы по 50 записей и порядок `(created_at, id)`; запрос использует существующий индекс `(ticket_id, created_at, id)` без новой migration. Retry/cancellation controls показываются только для сообщений выбранного ticket; server actions также проверяют ticket_id. Доступ к истории не перепривязывает сообщения к ticket, не меняет lifecycle и не создаёт decisions или jobs.

Отдельно от выборки истории, даты создания и закрытия ticket и timestamps сообщений форматируются в `Europe/Moscow` с пометкой «МСК». Преобразование применяется к копии Carbon date только при отображении; приложение, timestamps в PostgreSQL и queue timers сохраняют UTC.

Точная верстка и формулировки Telegram-сообщений не являются domain contract. Presentation concepts:

- `/start` кратко сообщает не отправлять банковские карты, пароли и SMS-коды и объясняет, что поддержка акции их не запрашивает;
- уведомление об эскалации показывает `Номер обращения: #...` и может повторить сокращённое safety warning, если это не перегружает сообщение;
- повтор safety warning при эскалации рекомендован, но не является обязательным для каждого сообщения;
- ответ оператора начинается с привязки к ticket, а optional цитата короткая и redacted;
- уведомление о сработавшем redaction не повторяет скрытое значение и говорит только, что чувствительные данные удалены и не нужны для поддержки.

### 10. Sanitization и privacy

Sanitizer вызывается в webhook use case до открытия persistence path для message body. Raw body существует только в памяти текущего запроса, после sanitization не передаётся дальше и не включается в сохранённый raw Telegram payload или queue payload. В PostgreSQL сохраняются только redacted body и optional `redaction_types` со значениями из закрытого списка `payment_card`, `otp`, `password`; исходные значения в metadata отсутствуют.

Минимальные detection rules MVP:

- **Payment card:** маскировать группы вида `4x4`, включая evaluation case `2200 1234 5678 9012`; для непрерывных или иначе сгруппированных последовательностей 13–19 цифр использовать форму и Luhn как сигналы, не маскируя любое длинное число безусловно.
- **OTP/SMS code:** маскировать короткое числовое или буквенно-числовое значение только рядом с явными маркерами `SMS`, `OTP`, `код из SMS`, `одноразовый код` и близкими вариантами.
- **Password:** после `пароль`, `password`, `pwd` разделитель или кавычки обозначают значение; без них первый token должен содержать цифру или password punctuation. Дополнительно одно значение после `мой пароль` / `my password` перед концом строки, запятой или точкой с запятой маскируется независимо от наличия цифр. Шаблон явного/quoted значения применяется первым, чтобы не оставить часть секрета после разделителя внутри кавычек. Word whitelist не используется; обычные многословные фразы о входе сохраняются. Однословные owned-фразы неоднозначны и консервативно считаются раскрытием секрета.
- **Ordinary numeric text:** даты, суммы, количество товаров, номера обращений и другие числа без card-like формы или sensitive context оставлять без изменений.
- **Phone:** автоматически не маскировать, поскольку участник может использовать номер как идентификатор аккаунта; при этом application logs содержат только технические IDs/status/error codes и никогда не содержат message body.

Один и тот же redacted body используется для persistence, LLM input, operator UI и Telegram quote. Логи получают только update/message/ticket IDs, redaction types и безопасные error codes. При наличии `redaction_types` после commit создаётся отдельное pending Telegram notification без исходного значения.

Стратегия намеренно не является универсальной DLP: она покрывает три явно заданных класса, сохраняет обычный числовой текст и допускает дальнейшее расширение только по подтверждённым evaluation/production случаям.

### 11. Statistics calculations

Provisional определения из specs реализуются обычными PostgreSQL aggregates:

- `bot resolved`: число исходящих сообщений бота без ticket со статусом `sent`; самостоятельные ответы и отказы учитываются, mixed и уведомления об эскалации исключаются. Число подготовленных ответов и состояния `pending`, `failed`, `cancelled` показаны отдельно.
- `escalated`: число tickets, а не число их сообщений.
- average operator response time: `AVG(first_operator_replied_at - tickets.created_at)` только для ненулевого `first_operator_replied_at`.

`first_operator_replied_at` устанавливается один раз в transaction успешной доставки первого operator message по его `delivered_at`, независимо от resolves_ticket и перехода в resolved. Pending, failed и cancelled ответы не участвуют в average; отменённые operator replies имеют отдельный счётчик. Forward data migration исправляет исторические timestamps по первой успешной доставке и очищает значение у tickets без отправленных ответов. Расчёт не вызывает LLM и не мутирует данные. Определения изолируются в одном query boundary.

### 12. Transaction boundaries summary

1. **Ingestion:** unique update + participant + locked active ticket + redacted inbound + reopen/cancel pending intent + optional notification/jobs; commit.
2. **Decision application:** прежние participant -> ticket -> message locks и recheck; общий attach/reopen либо прежние decision/ticket/outbound side effects; commit.
3. **Operator reply:** validate locked ticket + reopen/cancel previous intent + pending message с resolves_ticket + delivery job; commit.
4. **Delivery success:** lock ticket/message + mark sent + first response timestamp; при актуальном resolves_ticket перейти open -> resolved и записать delayed auto-close; commit.
5. **Manual/auto close:** lock ticket, проверить status/таймер, closed и cancellation недоставленных messages; commit.

В production flow внешние HTTP calls выполняются вне DB-транзакции. Offline evaluation использует отдельную тестовую БД и rollback транзакцию на каждый случай, чтобы не сохранять fixtures; Telegram не отправляется; ProcessIncomingMessage выполняется штатным database Worker в изолированной временной queue.

## Risks / Trade-offs

- **LLM hallucination** -> structured decisions, sole factual source, no administrative tools, validation and escalation on failure.
- **LLM outage or malformed response** -> database queue retries with backoff and idempotent `llm_failure` fallback.
- **Duplicate updates/jobs** -> unique update ID, unique decision per message, partial unique active-ticket index and state checks under row locks.
- **Telegram ambiguous timeout** -> persisted delivery state and retry; residual duplicate-delivery risk documented.
- **Sensitive data leakage** -> redact before persistence, discard raw body, reuse only redacted text downstream, avoid body logging, escape operator UI and keep secrets in environment-backed config.
- **Over-redaction** -> contextual OTP/password rules and card-shape checks preserve ordinary numeric text; grouped `4x4` sequences intentionally prefer safety.
- **Under-redaction outside known patterns** -> MVP covers only cards, explicit OTP/SMS codes and explicit passwords; universal DLP is deliberately rejected until real cases justify it.
- **Database queue contention** -> acceptable for pilot volume; move to Redis only after measured throughput or latency problems.
- **Provisional metric semantics change** -> metrics isolated behind deterministic queries over existing timestamps and relations.

## Migration Plan

1. Add PostgreSQL and database queue configuration, then create domain and Laravel queue tables.
2. Seed one operator account through environment-provided credentials.
3. Configure Telegram webhook and LLM credentials outside the repository.
4. Start app and queue worker through Docker Compose; run migrations before accepting updates.
5. Validate with faked integration tests, then run the 25-message evaluation dataset.

Перед обновлением schema/application остановить webhook и workers. Forward migration переводит старые waiting_for_user в open, переименовывает waiting_since в resolved_since, удаляет revision columns и добавляет resolves_ticket=false. Active-ticket unique и queue indexes включают open/resolved. История и first-response timestamps сохраняются; legacy timer jobs не применяются. Rollback восстанавливает прежнюю schema, но старое ожидание у reopened historical tickets не восстанавливается.

## Provisional Decisions Pending Manager Confirmation

- Mixed request: grounded answer plus simultaneous escalation of the unresolved part.
- `bot resolved`: one fully automated inbound message without ticket.
- `escalated`: one created ticket.
- Average operator response: ticket creation to first saved operator reply; unanswered tickets excluded.

Changing these definitions may require spec and query/test updates, but the persisted message, decision and timestamp model supports either likely interpretation.

## Public pilot configuration

Compose запускает Nginx + PHP-FPM в существующем app container, APP_ENV=production и APP_DEBUG=false. APP_KEY обязателен, передаётся из локального environment всем контейнерам и не генерируется при build/restart. Host HTTP port привязан к loopback; HTTPS завершается на локальном ngrok/TLS proxy, который задаёт X-Forwarded-Proto. Для публичного доступа задаются HTTPS APP_URL и SESSION_SECURE_COOKIE=true. Это пилотная конфигурация, а не обещание управляемого production hosting, backup или monitoring.

## Обязательные уточнения review

Password detection не содержит word whitelist: явный разделитель или кавычки обозначают фразу; без них первый token должен содержать цифру/password punctuation либо быть единственным значением после `мой пароль` / `my password`. Многословная неразмеченная prose сохраняется; неоднозначный owned single token маскируется в пользу безопасности.

EvaluateSupport использует штатный database Worker: job tries/backoff и terminal failed(), без принудительного fallback после первой временной ошибки. Отчёт показывает decision и validation failures (включая немедленный JobFailed после invalid result) в model result, safe HTTP/connection failures отдельно в infrastructure reason, attempts и конечный ответ; raw provider/error body не сохраняется.

Revision lifecycle из review заменён явно запрошенным open/resolved/closed; новые counters или generation columns не добавляются.
