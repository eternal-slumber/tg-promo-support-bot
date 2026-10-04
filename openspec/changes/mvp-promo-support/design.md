# Design

## Context

Мотивация и границы описаны в `proposal.md`, наблюдаемое поведение — в трёх capability specs. Приложение использует Laravel 13, PostgreSQL, Livewire и Docker Compose с app и тремя queue workers: AI/default, Telegram delivery и maintenance. Внешними интеграциями MVP являются только Telegram Bot API и выбранный LLM provider.

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

Laravel app и database queue workers запускаются через Docker Compose; отдельные микросервисы или event consumers не используются.

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
  -> continue conversation: reopen resolved
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
  context_message_ids JSONB NULL (fixed membership, references sanitized messages)
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
  source_message_id FK -> messages.id NULL (bot response origin)
  ticket_event NULL (ticket_closed for closure notices)
  direction: inbound | outbound
  author: participant | bot | operator | system
  body (redacted application text)
  redaction_types JSONB NULL
  delivery_status: pending | sent | failed | cancelled | NULL
  telegram_message_id NULL
  delivered_at NULL
  delivery_attempts
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

`PromotionRules` строит каталог numbered clauses, сохраняя multiline списки. Validator проверяет точный numeric ID и принадлежность непустой цитаты именно этому пункту, нормализуя только whitespace. В provider JSON Schema режиме задаётся одна схема `support_decision` с четырьмя полями и строгой структурой evidence. `LLM_RESPONSE_FORMAT` поддерживает JSON mode/text/LM Studio; на попытку используется один LLM request с общим для него timeout.

После ответа модели выполняются только deterministic checks JSON/schema, decision/reason, rule IDs и quote membership. Отдельного semantic verifier нет. Наличие настоящей цитаты подтверждает источник, но само по себе не доказывает семантическое соответствие каждого утверждения свободного answer; качество модели оценивается отдельно. Невалидный result не сохраняется и не отправляется как factual answer: используется существующая safe escalation с `llm_failure`.

Prompt-файлы версионируются отдельно в репозитории. В decision сохраняется структурированный результат и hash factual source для воспроизводимости, но не полный system prompt. Vector database отклонена: один небольшой документ правил помещается в prompt и является единственным knowledge source.

### 6. Telegram adapter и delivery tracking

Inbound webhook проверяет `X-Telegram-Bot-Api-Secret-Token` через `hash_equals` с непустым `config('telegram.webhook_secret')` до parsing и side effects. Missing/wrong token или отсутствующая конфигурация дают HTTP 403. CSRF exception сохраняется; legacy callbacks считаются unsupported после аутентификации webhook.

Parser отличает распознаваемые non-text сообщения участника в private chat от служебных событий и group updates. Для non-text DTO содержит только транспортные IDs, без caption и file metadata. Ingestion сохраняет update metadata и создаёт text-only fallback через существующий `createPendingMessage`: вопрос и текст подписи нужно отправить отдельным текстовым сообщением. Уведомление и delivery job сохраняются в той же transaction; duplicate update не создаёт повтор, существующий notice limiter допускает одно уведомление в минуту. LLM не вызывается, его quota не расходуется; ticket не создаётся и при active ticket продолжает переписку и возвращает resolved в open. При наличии active ticket уведомление относится к его истории. Файлы и подписи не принимаются как вопросы в этом MVP.

Telegram adapter отправляет `chat_id`, `text` и `reply_markup={"remove_keyboard": true}` (ReplyKeyboardRemove) для каждого исходящего сообщения, включая `/start`, bot/system сообщения и operator replies. Это удаляет сохранённую Telegram-клиентом старую feedback-клавиатуру при ближайшей успешной доставке, без новых сообщений, полей или lifecycle-команд. ReplyKeyboardMarkup, inline buttons и callback acknowledgement не создаются. Adapter задаёт explicit timeout и переводит API/network errors в типизированные ошибки.

Лимит текста централизован в `TelegramOutboundMessage::MaxTextLength` (4096 Unicode символов); длина и обрезка используют `mb_*`. Operator reply до persistence проверяется под ticket lock с учётом реального заголовка и короткой redacted quote из того же presentation builder. Превышение бюджета даёт validation error без создания Message/job. Operator reply не обрезается и не разбивается: ответ отправляется одним сообщением без feedback keyboard. Для bot/system сообщений, включая grounded LLM ответы, presentation обрезает слишком длинный текст с пометкой `… [сообщение сокращено]`; полный sanitized body сохраняется в истории. Delivery job и Telegram adapter дополнительно отклоняют oversized payload до API-вызова с безопасной non-retryable ошибкой `telegram_message_too_long`. Такая ошибка сохраняет сообщение как `failed` и не переводит ticket в `resolved`.

Каждый исходящий ответ сначала сохраняется как `pending`; затем `DeliverTelegramMessage` отправляет его после commit. При успехе message становится `sent`, сохраняются Telegram message ID и `delivered_at`. После окончательного сбоя message становится `failed`, сохраняется безопасный error code, но body не удаляется.

Создание operator reply выполняется под ticket row lock. Пока есть pending или failed operator reply, следующий создать нельзя. После sent или явной cancellation переписка продолжается. Новый ответ в resolved возвращает ticket в open и очищает resolved_since до постановки delivery job. Retry использует тот же message и под ticket/message locks переводит failed в pending. Delivery lock не допускает отмену или retry во время HTTP. Уже существующие несколько unfinished replies доставляются по ID; поздний reply ждёт завершения предыдущего.

Successful delivery только отмечает message sent и однократно фиксирует first_operator_replied_at. Она никогда не решает ticket и не создаёт auto-close. Manual/auto close сохраняют unfinished operator replies; delivery после закрытия также фиксирует sent и first response, не изменяя closed. Недоставленные bot/system сообщения закрытого обращения отменяются; preflight delivery повторяет проверку статуса. Единственное новое исключение — созданное при закрытии System outbound Message с ticket_event=ticket_closed: оно доставляется только для closed ticket. Cancellation — отдельное явное действие для pending/failed operator message, включая closed ticket.

### 7. Ticket lifecycle

```text
open -- explicit Mark resolved ----------------------------> resolved
resolved -- participant message or new operator reply ------> open
resolved -- matching expired auto-close --------------------> closed
open/resolved -- operator close ----------------------------> closed
closed -- terminal
```

«Отметить решённым» отдельно от отправки ответа выполняет переход и запись delayed job в одной PostgreSQL transaction. Повторное решение resolved отклоняется и не сдвигает таймер. TICKET_AUTO_CLOSE_HOURS остаётся configurable, default 24. AutoCloseTicket под row lock проверяет resolved, точное resolved_since и истечение периода. Timestamp хранится с микросекундами. Payload без resolvedSince игнорируется; stale jobs после reopen/manual close/new resolution — no-op.

### 8. Participant messages

Feedback/reply keyboard удалены. Любой participant text, включая прежние тексты кнопок и /start, является обычным сообщением. При active ticket он сохраняется в его истории без LLM и возвращает resolved в open; /start сохраняет safety warning. Non-text private message также возвращает resolved в open и получает существующий text-only fallback. После closed новый текст проходит прежнюю обычную маршрутизацию, включая прежние тексты кнопок; старый ticket остаётся terminal. Legacy inline callbacks остаются ignored.

### 9. Operator UI и authentication

Панель использует обычную Laravel session authentication и одну заранее созданную operator account. Все operator routes защищены `auth`; self-registration отсутствует.

На clean start Compose требует `OPERATOR_EMAIL` и `OPERATOR_PASSWORD`, ожидает PostgreSQL healthcheck, затем app последовательно выполняет migrations и `db:seed --force --no-interaction` до запуска HTTP server. Queue worker ждёт app healthcheck. Credentials читаются seeder через environment-backed config; непустой пароль и valid email обязательны, development fallback отсутствует. `firstOrCreate` по unique email обеспечивает идемпотентность для того же email; пароль хранится через `User` hashed cast. Повторный startup не обновляет существующий пароль. `.env.example` содержит только development email и пустой пароль; реальные credentials задаются локально.

Livewire отображает очередь, историю, форму ответа, delivery state, ручное закрытие и три метрики. Очередь по умолчанию показывает `open` и `resolved`; фильтры также позволяют просмотреть `closed` или все обращения, сортируя их от новых к старым. В closed нельзя отправлять новый ответ или менять lifecycle; retry/cancel существующего reply остаются доступны. Достаточно server-driven navigation и refresh/polling; WebSockets и SPA отклонены. User-provided text выводится только через escaped Blade syntax, без raw HTML.

История выбирается через `selectedTicket.messages()` и содержит только записи с `ticket_id` выбранного обращения. Предыдущие и последующие tickets одного participant, включая closed, имеют независимые истории. Cursor pagination сохраняет страницы по 50 записей и порядок `(created_at, id)`; запрос использует существующий индекс `(ticket_id, created_at, id)`. Retry/cancellation controls показываются только для сообщений выбранного ticket; server actions также проверяют ticket_id. Доступ к истории не перепривязывает сообщения к ticket, не меняет lifecycle и не создаёт decisions или jobs.

Отдельный read-only блок «Контекст до обращения» читает только сохранённые `tickets.context_message_ids`, с проверкой participant_id и собственной cursor pagination по 50 записей в порядке ID. Состав фиксируется внутри transaction создания ticket до привязки исходного сообщения эскалации. Нижняя граница — ID System Message `ticket_closed` предыдущего closed ticket; для первого ticket — 0. Верхняя граница — ID исходного сообщения эскалации. Включаются unticketed participant inbound и sent/delivered unticketed bot outbound, чей `source_message_id` входит в выбранные вопросы. System/operator, другие tickets/participants, поздние ответы на старые вопросы и недоставленные ответы исключаются. Новые сообщения и late delivery не меняют список. Санитизированный текст не копируется и не перепривязывается; source_message_id — metadata сохранения ответа, без изменений AI requests, decision validation, retries или routing.

Для исторического closed ticket без события закрытия используется консервативная нижняя граница: максимальный ID сообщений participant с created_at <= closed_at (при отсутствующем closed_at — created_at ticket). Из-за старой секундной точности это может исключить первые сообщения нового диалога в ту же секунду. Исторические tickets имеют null context_message_ids и не получают ретроспективный snapshot; старые ответы без source_message_id не включаются, поскольку их происхождение невозможно подтвердить.

Граница «Обращение №N создано» отображается по самому ticket, без дополнительной outbound Message. При manual/auto close после отмены stale bot/system messages в той же transaction создаётся System Message с ticket_id закрытого обращения, ticket_event=ticket_closed, номером, способом закрытия и пояснением о новом диалоге, затем существующая database delivery job. Повторные/stale close не создают Message/job. Событие закрытия одновременно служит точной нижней границей следующего контекста; новые таблицы, revision/generation counters и повторные текстовые snapshots не нужны.

Отдельно от выборки истории, даты создания и закрытия ticket и timestamps сообщений форматируются в `Europe/Moscow` с пометкой «МСК». Преобразование применяется к копии Carbon date только при отображении; приложение, timestamps в PostgreSQL и queue timers сохраняют UTC.

Точная верстка и формулировки Telegram-сообщений не являются domain contract. Presentation concepts:

- `/start` кратко сообщает не отправлять банковские карты, пароли и SMS-коды и объясняет, что поддержка акции их не запрашивает;
- уведомление об эскалации сообщает о передаче вопроса оператору и показывает `Номер обращения: #...`;
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

Один и тот же redacted body используется для persistence, LLM input, operator UI и Telegram quote. Логи получают только update/message/ticket IDs, redaction types и безопасные error codes. При срабатывании redaction отдельное pending Telegram notification без исходного значения и его job сохраняются в transaction ingestion; worker видит их после commit.

Стратегия намеренно не является универсальной DLP: она покрывает три явно заданных класса, сохраняет обычный числовой текст и допускает дальнейшее расширение только по подтверждённым evaluation/production случаям.

### 11. Statistics calculations

Provisional определения из specs реализуются обычными PostgreSQL aggregates:

- `bot resolved`: число самостоятельных исходящих bot answers по правилам без ticket со статусом `sent` и ненулевым `delivered_at`. Фиксированный безопасный refuse, mixed и уведомления об эскалации/системные предупреждения исключаются. Число подготовленных ответов и состояния `pending`, `failed`, `cancelled` показаны отдельно и также исключают refuse.
- `escalated`: число tickets, а не число их сообщений.
- average operator response time: `AVG(first_operator_replied_at - tickets.created_at)` только для ненулевого `first_operator_replied_at`.

`first_operator_replied_at` устанавливается один раз в transaction успешной доставки первого operator message по его `delivered_at`, независимо от статуса ticket. Pending, failed и cancelled ответы не участвуют в average; отменённые operator replies имеют отдельный счётчик. Forward data migration исправляет исторические timestamps по первой успешной доставке и очищает значение у tickets без отправленных ответов. Расчёт не вызывает LLM и не мутирует данные. Определения изолируются в одном query boundary.

### 12. Transaction boundaries summary

1. **Ingestion:** unique update + participant + locked active ticket + redacted inbound + reopen + optional notification/jobs; commit.
2. **Decision application:** прежние participant -> ticket -> message locks и recheck; общий attach/reopen либо прежние decision/ticket/outbound side effects; commit.
3. **Operator reply:** validate locked ticket + no unfinished operator replies + reopen + pending message + delivery job; commit.
4. **Mark resolved:** lock ticket + open -> resolved + delayed auto-close; commit.
5. **Delivery success:** lock ticket/message + mark sent + first response timestamp; commit без lifecycle transitions.
6. **Manual/auto close:** lock ticket + status/timer checks + closed + cancellation только unfinished bot/system messages + explicit System closure notice and database delivery job; commit. Queue insert failure откатывает закрытие и cancellation.
7. **Cancel/retry reply:** delivery lock + ticket/message locks + explicit message state/job; commit.

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

Перед обновлением schema/application остановить webhook и workers, выполнить migrations новым кодом и перезапустить обновлённые процессы. Текущая схема содержит open/resolved/closed, resolved_since с микросекундами, context_message_ids, source_message_id и ticket_event. Active-ticket indexes включают open/resolved. Сохраняются история, delivery states, pending/failed operator replies и first-response timestamps; stale timers не закрывают reopened ticket.

## Provisional Decisions Pending Manager Confirmation

- Mixed request: grounded answer plus simultaneous escalation of the unresolved part.
- `bot resolved`: one delivered standalone bot answer without ticket, excluding refuse.
- `escalated`: one created ticket.
- Average operator response: ticket creation to first delivered operator reply; unanswered tickets excluded.

Changing these definitions may require spec and query/test updates, but the persisted message, decision and timestamp model supports either likely interpretation.

## Public pilot configuration

Compose запускает Nginx + PHP-FPM в существующем app container, APP_ENV=production и APP_DEBUG=false. APP_KEY обязателен, передаётся из локального environment всем контейнерам и не генерируется при build/restart. Host HTTP port привязан к loopback; HTTPS завершается на локальном ngrok/TLS proxy, который задаёт X-Forwarded-Proto. Для публичного доступа задаются HTTPS APP_URL и SESSION_SECURE_COOKIE=true. Это пилотная конфигурация, а не обещание управляемого production hosting, backup или monitoring.

## Обязательные уточнения review

Password detection не содержит word whitelist: явный разделитель или кавычки обозначают фразу; без них первый token должен содержать цифру/password punctuation либо быть единственным значением после `мой пароль` / `my password`. Многословная неразмеченная prose сохраняется; неоднозначный owned single token маскируется в пользу безопасности.

EvaluateSupport использует штатный database Worker: job tries/backoff и terminal failed(), без принудительного fallback после первой временной ошибки. Отчёт показывает decision и validation failures (включая немедленный JobFailed после invalid result) в model result, safe HTTP/connection failures отдельно в infrastructure reason, attempts и конечный ответ; raw provider/error body не сохраняется.

## Evaluation сдаваемой single-call версии

Финальный evaluation 04.10.2026 обработал все 25 исходных обращений: **24 PASS / 0 PARTIAL / 1 FAIL**. Единственный FAIL — №18, где ответ не учёл перенос незавершённой проверки чеков по п. 8.1. Полный HTTP webhook → ingestion → database AI/Telegram workers → реальный LLM → deterministic validation → сохранение и delivery-client выполнен в отдельной PostgreSQL-БД; подменён только внешний Telegram sendMessage для синтетических чатов. Этот прогон не проверяет настоящий Telegram ingress/доставку или статистическую повторяемость.

Исторический two-call evaluation предыдущей реализации (17 верно / 6 неверно / 2 спорно) и диагностические повторы сохранены в `docs/evaluation.md`, отдельно от результата сдаваемой single-call версии.
