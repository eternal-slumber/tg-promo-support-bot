# Tasks

## 1. Bootstrap и конфигурация

- [ ] 1.1 Добавить Docker Compose services для Laravel app, PostgreSQL и database queue worker, настроить health/dependency order и проверить, что `docker compose config` проходит, а `docker compose up` запускает весь MVP одной командой.
- [ ] 1.2 Перевести application и queue connections на PostgreSQL/database driver, добавить jobs/failed-jobs storage и проверить миграции внутри Docker без Redis или другого broker.
- [ ] 1.3 Добавить Livewire в рамках заданного frontend stack и проверить, что базовая Blade/Livewire/Tailwind 4/Vite страница собирается командой `npm run build`.
- [ ] 1.4 Добавить environment-backed настройки Telegram, LLM, retry/timeout и `TICKET_AUTO_CLOSE_HOURS=24`, обновить безопасный `.env.example` и проверить, что populated secrets отсутствуют в tracked files.

## 2. Domain и PostgreSQL data model

- [ ] 2.1 Создать enums для ticket status, close reason, message author/direction, delivery status и decision type; проверить Pest unit tests на допустимые значения и переходы lifecycle.
- [ ] 2.2 Создать migrations и models/factories для Telegram participants, updates, tickets, messages и support decisions с внешними ключами, unique constraints и PostgreSQL partial unique index активного ticket; проверить migration/model feature tests.
- [ ] 2.3 Реализовать минимальные domain/application services для поиска/создания активного ticket и state transitions под row lock; проверить конкурентно значимые guard cases для `open`, `waiting_for_user` и `closed`.
- [ ] 2.4 Реализовать sanitizer банковских последовательностей до persistence, включая evaluation case №22, и проверить unit dataset для grouped/plain card-like values, безопасного текста и отсутствия исходного номера в результате.

## 3. Telegram ingestion

- [ ] 3.1 Реализовать Telegram webhook endpoint и parser минимально необходимых message/callback полей; проверить HTTP feature tests для валидного update, невалидного payload и отсутствия внешних HTTP-вызовов.
- [ ] 3.2 Сохранять unique Telegram update, participant и redacted inbound message одной транзакцией, dispatch AI job через `afterCommit()`, и проверить, что message существует до запуска job.
- [ ] 3.3 Сделать duplicate update успешным idempotent no-op и проверить, что повторный payload не создаёт второе message, ticket, job или статистический результат.
- [ ] 3.4 Для participant с `open` или `waiting_for_user` прикреплять новое сообщение к существующему ticket без AI-routing, а после `closed` запускать обычную классификацию; проверить все три feature scenarios.

## 4. LLM boundary и grounded support

- [ ] 4.1 Создать LLM contract, typed decision DTO и validator для `answer`, `escalate`, `mixed`, `refuse`; проверить unit tests для валидных, неполных, неизвестных и противоречивых structured outputs.
- [ ] 4.2 Добавить отдельные versioned prompt files и loader единственного factual source `docs/assignment/promo-rules.md`; проверить, что decision сохраняет source hash, но не полный runtime system prompt.
- [ ] 4.3 Реализовать один provider adapter через Laravel HTTP client с explicit connect/response timeout и безопасным error mapping; проверить exact endpoint fakes, `Http::preventStrayRequests()`, timeout, 429 и 5xx без реальной сети.
- [ ] 4.4 Реализовать `ProcessIncomingMessage` с queue attempts/backoff и early exit при существующем decision; проверить временный сбой с успешным retry и повтор job после сохранённого decision без второго LLM-вызова.
- [ ] 4.5 Применять валидный decision в короткой транзакции: grounded answer без ticket, unknown/participant-specific/off-topic escalation, mixed answer плюс один ticket и adversarial safe refusal; проверить acceptance cases для каждого варианта, включая requests №7, №12, №16, №23, №24 и №25.
- [ ] 4.6 Реализовать идемпотентный fallback после исчерпания retry с decision/ticket reason `llm_failure`; проверить timeout, invalid output и exhausted retries без потери message, второго ticket, второго ответа или двойного учёта.

## 5. Telegram delivery

- [ ] 5.1 Создать Telegram adapter для text messages, inline callbacks и callback acknowledgement с explicit timeout/error mapping; проверить HTTP fakes и отсутствие секретов/message body в логируемом контексте.
- [ ] 5.2 Реализовать presentation builder с номером ticket, короткой redacted quote и actions `Проблема решена` / `Не решило мою проблему`; проверить, что длинный текст обрезается и card value не повторяется.
- [ ] 5.3 Реализовать `DeliverTelegramMessage` для переходов `pending -> sent|failed`, сохранения Telegram message ID и повторной отправки того же record; проверить success, permanent failure и retry уже `sent` сообщения как no-op.

## 6. Operator authentication и UI

- [ ] 6.1 Реализовать session login для заранее созданного operator account без self-registration и ролей; проверить guest redirect, успешный login и отсутствие публичного registration endpoint.
- [ ] 6.2 Реализовать Livewire queue и conversation history с escaped participant content и delivery state; проверить component tests на порядок сообщений, фильтрацию незакрытых tickets и отсутствие raw HTML rendering.
- [ ] 6.3 Реализовать ответ оператора: сохранить `pending` message и первый response timestamp, dispatch delivery after commit, а после успешной доставки перевести `open -> waiting_for_user`; проверить DB, queue и Telegram side effects вместе.
- [ ] 6.4 Показывать failed delivery оператору и разрешать повторную отправку того же message record; проверить, что ошибка Telegram не удаляет ответ и не переводит ticket в `waiting_for_user`.

## 7. Callback и ticket lifecycle

- [ ] 7.1 Реализовать owner check для `resolved` / `unresolved` callback по Telegram user и idempotent status guard; проверить callback владельца, другого пользователя и повторную доставку без раскрытия чужого ticket.
- [ ] 7.2 Реализовать `waiting_for_user -> closed` с `user_confirmed` и `waiting_for_user -> open` для unresolved с приглашением уточнить; проверить оба перехода и добавление следующего clarification в тот же ticket без AI-routing.
- [ ] 7.3 Реализовать delayed `AutoCloseTicket` с configurable 24-hour delay и generation/status recheck под row lock; проверить auto-close через frozen time и stale job после reopening как no-op.
- [ ] 7.4 Реализовать ручное закрытие `open`/`waiting_for_user` оператором с `operator_closed`; проверить auth, допустимые статусы и idempotent повторное действие.

## 8. Statistics

- [ ] 8.1 Реализовать изолированный statistics query/service по provisional definitions для `bot resolved`, `escalated` и average first operator response time; проверить фиксированным dataset, что mixed исключён, follow-ups не увеличивают escalated, а unanswered tickets не входят в average.
- [ ] 8.2 Добавить статистику в Livewire operator panel и проверить component test, что повторный просмотр возвращает те же значения без LLM/Telegram вызовов и изменения persisted state.

## 9. Evaluation и документация поведения

- [ ] 9.1 Создать повторяемый evaluation runner для всех 25 сообщений из `docs/assignment/requests.md` через тот же application boundary и проверить, что отчёт содержит вопрос, ответ, признак эскалации, оценку и комментарий для всех 25 строк.
- [ ] 9.2 Зафиксировать результаты evaluation, спорные mixed/off-topic cases и изменения prompt в требуемой таблице; проверить наличие строк 1–25 и отсутствие неотредактированных чувствительных данных.
- [ ] 9.3 Обновить README инструкцией запуска с нуля, assumptions, provisional definitions, известными ограничениями и production follow-ups; проверить команды README в чистом Docker Compose запуске.
- [ ] 9.4 Добавить схему PostgreSQL и краткое объяснение ключей, partial unique active-ticket constraint, transactions и delivery state; сверить диаграмму с фактическими migrations.

## 10. Сквозная верификация

- [ ] 10.1 Запустить узкие Pest feature/unit suites каждого capability, затем полный `php artisan test --compact`, и устранить только дефекты поведения этого change.
- [ ] 10.2 Запустить `vendor/bin/pint --dirty --format agent`, `npm run build` и `openspec validate mvp-promo-support --strict`; проверить успешное завершение всех команд.
- [ ] 10.3 Выполнить Docker smoke flow: принять grounded question, создать escalation, ответить оператором, обработать resolved/unresolved и auto-close, затем сверить три метрики и delivery states.
- [ ] 10.4 Проверить repository secrets scan и итоговый diff: отсутствуют реальные Telegram/LLM keys, raw card value, незаявленная инфраструктура и функциональность вне specs.
