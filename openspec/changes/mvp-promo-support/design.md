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
  -> COMMIT
  -> dispatch AI job afterCommit only when no active ticket exists
```

Конфликт уникального `telegram_updates.update_id` означает уже принятый update и завершается успешным webhook response без side effects. Для сообщения при активном ticket AI job не создаётся.

Выбран explicit `afterCommit()` для AI dispatch, даже если queue connection позднее получит глобальный `after_commit`: связь между durable message и job остаётся видимой в use case. Вызов LLM внутри webhook отклонён, потому что увеличивает latency и теряет сообщение при timeout до persistence.

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
  status: open | waiting_for_user | closed
  escalation_reason
  first_operator_replied_at NULL
  waiting_since NULL
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

Связи: participant имеет много updates, messages и tickets; ticket имеет много messages; inbound message имеет не более одного decision. Частичный уникальный индекс PostgreSQL на `tickets(participant_id) WHERE status IN ('open', 'waiting_for_user')` обеспечивает одно незакрытое обращение даже при конкурентной обработке.

Отдельная events/audit таблица не вводится: timestamps, decision и message delivery state достаточны для обязательных метрик и диагностики MVP. Event sourcing отклонён как несоразмерный.

### 4. AI job, retry и идемпотентность решения

`ProcessIncomingMessage` получает только message ID. Перед LLM-вызовом job проверяет существование `support_decisions.message_id`; сохранённое решение завершает job без повторного вызова.

LLM-вызов выполняется вне длинной DB-транзакции. Provider возвращает валидированный список смысловых частей (`rule_answer`, `participant_specific`, `not_in_rules`, `prompt_injection`) без raw participant text. PHP детерминированно строит final decision: только grounded части дают `answer`; grounded вместе с unresolved частями дают `mixed`; unresolved части дают `escalate`; `prompt_injection` даёт `refuse`. После этого короткая транзакция блокирует message, повторно проверяет отсутствие decision, сохраняет final decision и безопасный structured analysis, затем создаёт ровно необходимые side effects:

- `answer`: одно исходящее bot message;
- `escalate`: один ticket и уведомление;
- `mixed`: один ticket, grounded answer и уведомление;
- `refuse`: одно исходящее bot message без административного действия.

Unique decision constraint и частичный ticket index являются последней защитой от конкурентных попыток. Исходящие сообщения получают стабильную связь с decision/use case, позволяющую запретить повторное создание на уровне DB constraint или идемпотентного application lookup.

Job имеет ограниченное число attempts, timeout и backoff array. Transient transport/5xx/429/timeout приводит к retry. Невалидный structured output считается неуспешной попыткой. После исчерпания attempts failure handler выполняет короткую идемпотентную транзакцию: если decision уже существует — ничего не делает; иначе сохраняет escalation decision с `llm_failure`, находит или создаёт ticket и создаёт единственное уведомление.

Внутренний HTTP retry LLM adapter ограничивается либо отключается, чтобы произведение HTTP retries и queue attempts не создавало непредсказуемо долгую обработку. Queue retry является основной политикой.

Альтернатива `ShouldBeUnique` может быть дополнительным guard, но не заменяет DB constraints: cache uniqueness не является источником истины, а выбранный MVP не требует отдельного Redis cache.

### 5. LLM adapter boundary

Application contract принимает redacted participant text и актуальный текст `promo-rules.md`, возвращая typed analysis DTO со списком смысловых частей. Отдельный application builder детерминированно преобразует этот analysis в typed final decision. Provider adapter отвечает за authentication, explicit connect/response timeouts и преобразование provider response.

Analysis validator разрешает только известные kinds и обязательные поля каждой части; application builder создаёт только допустимые final decisions. Application layer, а не модель, решает, какие записи создать. У LLM нет tools для изменения ticket, победителей, чеков или аккаунтов.

Provider payload содержит два сообщения: `system` включает только application prompt, contract и promotion rules; `user` содержит только sanitized participant text. Runtime participant text никогда не интерполируется в system prompt.

Каждая grounded часть имеет поля `kind`, `answer`, `evidence: [{rule_id, quote}]`; остальные части имеют `answer: null` и пустой evidence. `PromotionRules` строит каталог numbered clauses из `promo-rules.md`, сохраняя multiline списки в соответствующем пункте. Validator проверяет существование точного numeric ID и принадлежность непустой цитаты именно этому пункту (нормализуется только whitespace). Произвольные `source_rules` от provider больше не принимаются. Typed analysis сохраняет проверенный evidence и derived sourceRules. Factual answer собирается из полного текста подтверждённых пунктов каталога, а не из free-form ответа модели или обрезанной цитаты, чтобы не терять отрицания и условия. Невалидный evidence вызывает существующий processing failure/retry и после исчерпания attempts — `llm_failure` escalation. Каталог не доказывает смысловую релевантность выбранного пункта; это остаётся задачей semantic analysis и evaluation.

Prompt-файлы версионируются отдельно в репозитории. В decision сохраняется структурированный результат и hash factual source для воспроизводимости, но не полный system prompt. Vector database отклонена: один небольшой документ правил помещается в prompt и является единственным knowledge source.

### 6. Telegram adapter и delivery tracking

Inbound webhook проверяет `X-Telegram-Bot-Api-Secret-Token` через `hash_equals` с непустым `config('telegram.webhook_secret')` до parsing и side effects. Missing/wrong token или отсутствующая конфигурация дают HTTP 403. CSRF exception сохраняется; callback ownership проверяется только после аутентификации webhook.

Telegram adapter предоставляет минимальные операции: отправить текст с optional inline keyboard и подтвердить callback. Он задаёт explicit timeout и переводит API/network errors в типизированные ошибки.

Каждый исходящий ответ сначала сохраняется как `pending`; затем `DeliverTelegramMessage` отправляет его после commit. При успехе message становится `sent`, сохраняются Telegram message ID и `delivered_at`. После окончательного сбоя message становится `failed`, сохраняется безопасный error code, но body не удаляется.

Создание operator reply выполняется под `lockForUpdate()` ticket в transaction. Для ticket допускается один незавершённый outbound operator reply (`pending` или `failed`): повторный submit отклоняется без новой Message/job, а retry использует прежний message ID. Manual close под тем же ticket lock запрещён при незавершённом operator reply. Перед Telegram-вызовом delivery job повторно проверяет ticket: operator message отправляется только для `open`; для закрытого ticket job ничего не отправляет. Pending/failed reply удерживает ticket в `open` до successful delivery, поэтому ручное закрытие не может обогнать его доставку.

Только успешная доставка ответа оператора переводит ticket `open -> waiting_for_user`, устанавливает `waiting_since` и dispatches delayed auto-close. Повтор delivery job для `sent` message является no-op. Для `failed` сообщения панель должна явно показывать ошибку и позволять повторную отправку тем же message record.

Telegram `sendMessage` не предоставляет application idempotency key. При timeout после фактического принятия Telegram API остаётся небольшой риск повторной доставки при retry; он документируется, поскольку устранение потребовало бы внешнего reconciliation, отсутствующего в MVP.

### 7. Ticket state machine

```text
                  successful operator delivery
        +----------------------------------------------+
        |                                              v
     +------+                                    +------------------+
     | open |                                    | waiting_for_user |
     +--+---+                                    +---+----------+---+
        ^                                            |          |
        | unresolved                                 | solved   | timeout
        +--------------------------------------------+          |
                                                            v   v
                                                          +--------+
                                                          | closed |
                                                          +--------+

open ---------------- operator close --------------------> closed
waiting_for_user ------- operator close -----------------> closed
```

Close reasons: `user_confirmed`, `auto_closed`, `operator_closed`. Закрытый ticket не переоткрывается: новое сообщение идёт через новый routing flow.

`AutoCloseTicket` получает ticket ID и ожидаемое значение `waiting_since` либо ID operator message. В транзакции job блокирует ticket и закрывает его только если статус всё ещё `waiting_for_user` и generation marker совпадает. Поэтому job от старого ответа ничего не делает после `unresolved`, нового ответа или ручного закрытия.

`TICKET_AUTO_CLOSE_HOURS` читается через application config; значение `.env` используется только в config file. MVP default — 24.

### 8. Callback security

Callback payload содержит action и public ticket reference, но не является доказательством владения. Handler по Telegram `from.id` находит participant, загружает ticket и проверяет `ticket.participant_id` до показа данных или перехода.

Telegram update ID обеспечивает идемпотентность повторной callback delivery. Сам переход дополнительно проверяет ожидаемый текущий статус под row lock: `resolved` и `unresolved` применимы только к `waiting_for_user`.

Подписанный или opaque callback token рассматривался, но для MVP не заменяет обязательную owner check и добавляет управление ключом/хранилищем. Его можно добавить позднее как defense in depth.

### 9. Operator UI и authentication

Панель использует обычную Laravel session authentication и одну заранее созданную operator account. Все operator routes защищены `auth`; self-registration отсутствует.

На clean start Compose требует `OPERATOR_EMAIL` и `OPERATOR_PASSWORD`, ожидает PostgreSQL healthcheck, затем app последовательно выполняет migrations и `db:seed --force --no-interaction` до запуска HTTP server. Queue worker ждёт app healthcheck. Credentials читаются seeder через environment-backed config; непустой пароль и valid email обязательны, development fallback отсутствует. `firstOrCreate` по unique email обеспечивает идемпотентность для того же email; пароль хранится через `User` hashed cast. Повторный startup не обновляет существующий пароль. `.env.example` содержит только development email и пустой пароль; реальные credentials задаются локально.

Livewire отображает очередь, историю, форму ответа, delivery state, ручное закрытие и три метрики. Очередь по умолчанию показывает `open` и `waiting_for_user`; фильтры также позволяют просмотреть `closed` или все обращения, сортируя их от новых к старым. Закрытые обращения read-only. Достаточно server-driven navigation и refresh/polling; WebSockets и SPA отклонены. User-provided text выводится только через escaped Blade syntax, без raw HTML.

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
- **Password:** маскировать значение только рядом с явными маркерами `пароль`, `password`, `pwd` и близкими вариантами; сам несекретный контекст сохранять.
- **Ordinary numeric text:** даты, суммы, количество товаров, номера обращений и другие числа без card-like формы или sensitive context оставлять без изменений.
- **Phone:** автоматически не маскировать, поскольку участник может использовать номер как идентификатор аккаунта; при этом application logs содержат только технические IDs/status/error codes и никогда не содержат message body.

Один и тот же redacted body используется для persistence, LLM input, operator UI и Telegram quote. Логи получают только update/message/ticket IDs, redaction types и безопасные error codes. При наличии `redaction_types` после commit создаётся отдельное pending Telegram notification без исходного значения.

Стратегия намеренно не является универсальной DLP: она покрывает три явно заданных класса, сохраняет обычный числовой текст и допускает дальнейшее расширение только по подтверждённым evaluation/production случаям.

### 11. Statistics calculations

Provisional определения из specs реализуются обычными PostgreSQL aggregates:

- `bot resolved`: число уникальных inbound messages с decision `answer` или `refuse`, не связанных с созданным ticket; mixed исключается.
- `escalated`: число tickets, а не число их сообщений.
- average operator response time: `AVG(first_operator_replied_at - tickets.created_at)` только для ненулевого `first_operator_replied_at`.

`first_operator_replied_at` устанавливается один раз при сохранении первого operator message, независимо от последующих delivery retries. Расчёт не вызывает LLM и не мутирует данные. Определения изолируются в одном query/service boundary, чтобы изменить их после ответа менеджера без изменения ingestion и ticket lifecycle.

### 12. Transaction boundaries summary

1. **Ingestion:** unique update + participant + redacted inbound message + safe redaction metadata и optional safety notification; commit; dispatch jobs after commit.
2. **Decision application:** lock message + unique decision + ticket/outbound messages; commit; dispatch delivery after commit.
3. **Operator reply:** validate ticket + save pending outbound message and first response timestamp; commit; dispatch delivery.
4. **Delivery success:** lock message + mark sent; для operator reply перевести ticket в waiting and set generation marker; commit; dispatch delayed auto-close.
5. **Callback/manual/auto close:** lock ticket, validate owner/status/generation, apply one state transition, commit.

External HTTP calls never выполняются внутри DB-транзакции.

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

Rollback for the MVP is stopping webhook traffic and workers before rolling back application migrations. No existing production data migration is required because the project is greenfield.

## Provisional Decisions Pending Manager Confirmation

- Mixed request: grounded answer plus simultaneous escalation of the unresolved part.
- `bot resolved`: one fully automated inbound message without ticket.
- `escalated`: one created ticket.
- Average operator response: ticket creation to first saved operator reply; unanswered tickets excluded.

Changing these definitions may require spec and query/test updates, but the persisted message, decision and timestamp model supports either likely interpretation.
