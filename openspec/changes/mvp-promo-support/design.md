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
  -> redact sensitive sequences and INSERT inbound message
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

LLM-вызов выполняется вне длинной DB-транзакции. После валидного ответа короткая транзакция блокирует message, повторно проверяет отсутствие decision, сохраняет decision и создаёт ровно необходимые side effects:

- `answer`: одно исходящее bot message;
- `escalate`: один ticket и уведомление;
- `mixed`: один ticket, grounded answer и уведомление;
- `refuse`: одно исходящее bot message без административного действия.

Unique decision constraint и частичный ticket index являются последней защитой от конкурентных попыток. Исходящие сообщения получают стабильную связь с decision/use case, позволяющую запретить повторное создание на уровне DB constraint или идемпотентного application lookup.

Job имеет ограниченное число attempts, timeout и backoff array. Transient transport/5xx/429/timeout приводит к retry. Невалидный structured output считается неуспешной попыткой. После исчерпания attempts failure handler выполняет короткую идемпотентную транзакцию: если decision уже существует — ничего не делает; иначе сохраняет escalation decision с `llm_failure`, находит или создаёт ticket и создаёт единственное уведомление.

Внутренний HTTP retry LLM adapter ограничивается либо отключается, чтобы произведение HTTP retries и queue attempts не создавало непредсказуемо долгую обработку. Queue retry является основной политикой.

Альтернатива `ShouldBeUnique` может быть дополнительным guard, но не заменяет DB constraints: cache uniqueness не является источником истины, а выбранный MVP не требует отдельного Redis cache.

### 5. LLM adapter boundary

Application contract принимает redacted participant text и актуальный текст `promo-rules.md`, возвращая typed decision DTO. Provider adapter отвечает за authentication, explicit connect/response timeouts и преобразование provider response.

Validator разрешает только известные decision types и обязательные поля. Application layer, а не модель, решает, какие записи создать. У LLM нет tools для изменения ticket, победителей, чеков или аккаунтов.

Prompt-файлы версионируются отдельно в репозитории. В decision сохраняется структурированный результат и hash factual source для воспроизводимости, но не полный system prompt. Vector database отклонена: один небольшой документ правил помещается в prompt и является единственным knowledge source.

### 6. Telegram adapter и delivery tracking

Telegram adapter предоставляет минимальные операции: отправить текст с optional inline keyboard и подтвердить callback. Он задаёт explicit timeout и переводит API/network errors в типизированные ошибки.

Каждый исходящий ответ сначала сохраняется как `pending`; затем `DeliverTelegramMessage` отправляет его после commit. При успехе message становится `sent`, сохраняются Telegram message ID и `delivered_at`. После окончательного сбоя message становится `failed`, сохраняется безопасный error code, но body не удаляется.

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

Livewire отображает очередь, историю, форму ответа, delivery state, ручное закрытие и три метрики. Достаточно server-driven navigation и refresh/polling; WebSockets и SPA отклонены. User-provided text выводится только через escaped Blade syntax, без raw HTML.

Точная верстка и формулировки Telegram-сообщений не являются domain contract. Presentation concept: уведомление показывает `Номер обращения: #...`, ответ начинается с привязки к ticket, а optional цитата короткая и redacted.

### 10. Sanitization и privacy

До persistence message body проходит узкий sanitizer для последовательностей, похожих на платёжную карту: пробелы/дефисы нормализуются для проверки, последовательности 13–19 цифр маскируются целиком либо оставляют только безопасный хвост. Для снижения false positive предпочтительна проверка Luhn; evaluation case `2200 1234 5678 9012` также должен редактироваться даже при невалидном Luhn, поэтому формат банковской группы `4x4` маскируется независимо.

В PostgreSQL сохраняется redacted body, а не полный raw Telegram payload. Metadata update/message IDs сохраняется отдельно. Номера телефонов могут оставаться в истории как добровольно переданный идентификатор обращения, но application logs не включают message body, prompt, access tokens или provider response.

Один sanitizer применяется перед persistence, поэтому LLM, операторская панель, Telegram quote и логи не получают исходный номер карты. Это намеренно минимальная стратегия, а не общая классификация всех персональных данных.

### 11. Statistics calculations

Provisional определения из specs реализуются обычными PostgreSQL aggregates:

- `bot resolved`: число уникальных inbound messages с decision `answer` или `refuse`, не связанных с созданным ticket; mixed исключается.
- `escalated`: число tickets, а не число их сообщений.
- average operator response time: `AVG(first_operator_replied_at - tickets.created_at)` только для ненулевого `first_operator_replied_at`.

`first_operator_replied_at` устанавливается один раз при сохранении первого operator message, независимо от последующих delivery retries. Расчёт не вызывает LLM и не мутирует данные. Определения изолируются в одном query/service boundary, чтобы изменить их после ответа менеджера без изменения ingestion и ticket lifecycle.

### 12. Transaction boundaries summary

1. **Ingestion:** unique update + participant + redacted inbound message; commit; dispatch after commit.
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
- **Sensitive data leakage** -> redact before persistence, avoid raw payload and body logging, escape operator UI, keep secrets in environment-backed config.
- **Over-redaction** -> deliberately narrow card patterns; operators may lose a numeric identifier that resembles a card, accepted for MVP safety.
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
