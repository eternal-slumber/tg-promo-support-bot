# Tasks

## 1. Bootstrap и конфигурация

- [x] 1.1 Добавить Docker Compose services для Laravel app, PostgreSQL и database queue worker, настроить health/dependency order и проверить, что `docker compose config` проходит, а `docker compose up` запускает весь MVP одной командой.
- [x] 1.2 Перевести application и queue connections на PostgreSQL/database driver, добавить jobs/failed-jobs storage и проверить миграции внутри Docker без Redis или другого broker.
- [x] 1.3 Добавить Livewire в рамках заданного frontend stack и проверить, что базовая Blade/Livewire/Tailwind 4/Vite страница собирается командой `npm run build`.
- [x] 1.4 Добавить environment-backed настройки Telegram, LLM, retry/timeout и `TICKET_AUTO_CLOSE_HOURS=24`, обновить безопасный `.env.example` и проверить, что populated secrets отсутствуют в tracked files.

## 2. Domain и PostgreSQL data model

- [x] 2.1 Создать enums для ticket status, close reason, message author/direction, delivery status и decision type; проверить Pest unit tests на допустимые значения и переходы lifecycle.
- [x] 2.2 Создать migrations и models/factories для Telegram participants, updates, tickets, messages и support decisions с безопасной `redaction_types` metadata, внешними ключами, unique constraints и PostgreSQL partial unique index активного ticket; проверить migration/model feature tests и отсутствие поля для raw message body.
- [x] 2.3 Реализовать минимальные domain/application services для поиска/создания активного ticket и state transitions под row lock; проверить конкурентно значимые guard cases для `open`, `waiting_for_user` и `closed`.
- [x] 2.4 Реализовать sanitizer до persistence для card-like sequences, контекстных SMS/OTP-кодов и явно обозначенных паролей; проверить unit dataset для evaluation case №22, OTP/password context, обычных дат/сумм/количеств без ложного маскирования и сохранения телефонного номера.

## 3. Telegram ingestion

- [x] 3.1 Реализовать Telegram webhook endpoint и parser минимально необходимых private text message полей с успешным игнорированием legacy callback updates; проверить HTTP feature tests для валидного update, невалидного payload и отсутствия внешних HTTP-вызовов.
- [x] 3.2 Обработать `/start` отдельным safety message о картах, паролях и SMS-кодах без жёсткой фиксации точной формулировки; проверить acceptance test всех четырёх смысловых пунктов предупреждения.
- [x] 3.3 Сохранять unique Telegram update, participant, только redacted inbound message и safe redaction types одной транзакцией, атомарно записывать database jobs через `beforeCommit()` в той же PostgreSQL transaction, и проверить отсутствие raw card/OTP/password values в DB и queue payload до AI-обработки.
- [x] 3.4 При обнаруженном redaction создать краткое уведомление участнику без исходного значения; проверить один notification независимо от числа скрытых значений и metadata только из `payment_card`, `otp`, `password`.
- [x] 3.5 Сделать duplicate update успешным idempotent no-op и проверить, что повторный payload не создаёт второе message, ticket, job, redaction notification или статистический результат.
- [x] 3.6 Для participant с `open` или `waiting_for_user` прикреплять redacted сообщение к тому же ticket без AI-routing; в `waiting_for_user` подтверждение закрывает ticket, любой другой текст открывает его без LLM и новых кнопок; после `closed` новый вопрос классифицируется заново. Проверить transitions, сохранение истории, duplicate update и stale auto-close.

## 4. LLM boundary и grounded support

- [x] 4.1 Создать LLM contract, typed decision DTO и validator для `answer`, `escalate`, `mixed`, `refuse`; проверить unit tests для валидных, неполных, неизвестных и противоречивых structured outputs.
- [x] 4.2 Добавить отдельные versioned prompt files и loader единственного factual source `docs/assignment/promo-rules.md`; проверить, что decision сохраняет source hash, но не полный runtime system prompt.
- [x] 4.3 Реализовать один provider adapter через Laravel HTTP client с explicit connect/response timeout и безопасным error mapping; проверить exact endpoint fakes, `Http::preventStrayRequests()`, timeout, 429, 5xx и получение только redacted participant text без реальной сети.
- [x] 4.4 Реализовать `ProcessIncomingMessage` с queue attempts/backoff и early exit при существующем decision; проверить временный сбой с успешным retry и повтор job после сохранённого decision без второго LLM-вызова.
- [x] 4.5 Применять валидный decision в короткой транзакции: grounded answer без ticket, unknown/participant-specific/off-topic escalation, mixed answer плюс один ticket и adversarial safe refusal; проверить acceptance cases для каждого варианта, включая requests №7, №12, №16, №23, №24 и №25.
- [x] 4.6 Реализовать идемпотентный fallback после исчерпания retry с decision/ticket reason `llm_failure`; проверить timeout, invalid output и exhausted retries без потери message, второго ticket, второго ответа или двойного учёта.

## 5. Telegram delivery

- [x] 5.1 Создать Telegram adapter для text messages и ReplyKeyboardMarkup без inline callbacks и callback acknowledgement с explicit timeout/error mapping; проверить HTTP fakes и отсутствие секретов, raw body, телефонов и sensitive values в логируемом контексте.
- [x] 5.2 Реализовать presentation builders для номера ticket, короткой redacted quote, redaction notification и actions `Проблема решена` / `Не решило`; разрешить optional краткий safety reminder при эскалации и проверить, что он не обязателен, длинный текст обрезается, а hidden values не повторяются.
- [x] 5.3 Реализовать `DeliverTelegramMessage` для переходов `pending -> sent|failed`, сохранения Telegram message ID и повторной отправки того же record; проверить success, permanent failure и retry уже `sent` сообщения как no-op.

## 6. Operator authentication и UI

- [x] 6.1 Реализовать session login для заранее созданного operator account без self-registration и ролей; проверить guest redirect, успешный login и отсутствие публичного registration endpoint.
- [x] 6.2 Реализовать Livewire queue и conversation history с escaped redacted participant content и delivery state; проверить component tests на порядок сообщений, фильтрацию незакрытых tickets, отсутствие raw HTML и отсутствие исходных card/OTP/password values.
- [x] 6.3 Реализовать ответ оператора: сохранить `pending` message и первый response timestamp, атомарно записать delivery job в той же transaction, а после successful delivery при неизменной input revision перевести `open -> waiting_for_user`; проверить DB, queue и Telegram side effects вместе.
- [x] 6.4 Показывать failed delivery оператору и разрешать повторную отправку того же message record; проверить, что ошибка Telegram не удаляет ответ и не переводит ticket в `waiting_for_user`.

## 7. Text feedback и ticket lifecycle

- [x] 7.1 Удалить inline callback parser/DTO/handler/acknowledgement и pending callback state; текстовый feedback применим только к собственному active ticket отправителя. Проверить owner isolation, duplicate text update и успешное игнорирование старых inline updates без side effects.
- [x] 7.2 Реализовать `waiting_for_user -> closed / user_confirmed` для текста `Проблема решена` и `waiting_for_user -> open` для `Не решило` или любого другого текста без LLM и новых кнопок. Сохранять feedback в том же ticket; проверить, что любой input после сохранения operator reply оставляет open, включая раннее подтверждение и input между отказом Telegram и retry, а раннее подтверждение остаётся в истории для ручного закрытия и stale auto-close даже при совпадающих timestamps.
- [x] 7.3 Реализовать delayed `AutoCloseTicket` с configurable 24-hour delay и generation/status recheck под row lock; проверить auto-close через frozen time и stale job после reopening как no-op.
- [x] 7.4 Реализовать ручное закрытие `open`/`waiting_for_user` оператором с `operator_closed`; проверить auth, допустимые статусы и idempotent повторное действие.

## 8. Statistics

- [x] 8.1 Реализовать изолированный statistics query/service по provisional definitions для `bot resolved`, `escalated` и average first operator response time; проверить фиксированным dataset, что mixed исключён, follow-ups не увеличивают escalated, а unanswered tickets не входят в average.
- [x] 8.2 Добавить статистику в Livewire operator panel и проверить component test, что повторный просмотр возвращает те же значения без LLM/Telegram вызовов и изменения persisted state.

## 9. Evaluation и документация поведения

- [x] 9.1 Создать повторяемый evaluation runner для всех 25 сообщений из `docs/assignment/requests.md` через тот же application boundary и проверить, что отчёт содержит вопрос, ответ, признак эскалации, оценку и комментарий для всех 25 строк.
- [x] 9.2 Зафиксировать результаты evaluation, спорные mixed/off-topic cases и изменения prompt в требуемой таблице; проверить наличие строк 1–25, redaction case №22 и отсутствие raw card/OTP/password values.
- [x] 9.3 Обновить README инструкцией запуска с нуля, assumptions, provisional definitions, известными ограничениями и production follow-ups; проверить команды README в чистом Docker Compose запуске.
- [x] 9.4 Добавить схему PostgreSQL и краткое объяснение ключей, partial unique active-ticket constraint, transactions и delivery state; сверить диаграмму с фактическими migrations.

## 10. Сквозная верификация

- [x] 10.1 Запустить узкие Pest feature/unit suites каждого capability, затем полный `php artisan test --compact`, и устранить только дефекты поведения этого change.
- [x] 10.2 Запустить `vendor/bin/pint --dirty --format agent`, `npm run build` и `openspec validate mvp-promo-support --strict`; проверить успешное завершение всех команд.
- [x] 10.3 Выполнить Docker smoke flow: принять grounded question, создать escalation, ответить оператором, обработать resolved/unresolved и auto-close, затем сверить три метрики и delivery states.
- [x] 10.4 Проверить repository secrets scan и итоговый diff: отсутствуют реальные Telegram/LLM keys, raw card/OTP/password values, sensitive message bodies в logging paths, незаявленная инфраструктура и функциональность вне specs.

## 11. Четыре обязательных пункта review

- [x] 11.1 Заменить Message.id ticket input_revision и snapshot operator reply; проверить late AI attach во время delivery и changed/unchanged revision.
- [x] 11.2 Убрать password word whitelist, проверить указанные leak/false-positive cases по syntax/value-like признакам.
- [x] 11.3 Зафиксировать явно согласованный revision lifecycle в OpenSpec без утверждения о прежнем согласовании удаления marker задним числом.
- [x] 11.4 Применить штатную ProcessIncomingMessage retry-policy в evaluation, разделить model result/infrastructure reason, повторить 25 cases; полный PostgreSQL suite, smoke, Pint, build и OpenSpec validation.

Правило раннего feedback подтверждено пользователем 03.10.2026: до фиксации успешной доставки подтверждение сохраняется в истории, но не закрывает ticket автоматически.

03.10.2026 все 25 cases прогнаны на локальной google/gemma-4-e4b со штатными retries и ручной оценкой: 17 верно, 6 неверно, 2 спорно. Задача 9.2 завершена как фиксация результатов, а не как подтверждение достаточного качества. №9 и №12 содержат ложные утверждения, пропущенные второй LLM-проверкой; пять cases дали llm_failure. Промпты по итогам этого прогона не менялись; для совместимости LM Studio добавлен LLM_RESPONSE_FORMAT=json_schema. Рабочая БД и доставка Telegram не затрагивались.


## 12. Один основной LLM-запрос

- [x] 12.1 Заменить analysis parts и второй LLM verifier одним structured decision с answer/evidence; deterministic PHP validation, trusted московская дата, сохранение пользовательского answer; удалить verifier prompt и ненужные production dependencies.
- [x] 12.2 Ограничить provider attempts тремя на сообщение: один HTTP request на queue attempt, retries только transient errors; invalid result сразу использует существующую safe escalation; ticket lifecycle, operator dashboard и delivery semantics не менять.
- [x] 12.3 Обновить OpenSpec, README и regression tests для single-call contract, evidence validation, safe fallback и HTTP request counts; выполнить узкие Pest tests, Pint и OpenSpec validation без реальных LLM-запросов.

## 14. Изоляция истории обращений

- [x] 14.1 Ограничить ленту operator dashboard выбранным ticket_id; исключить другие обращения того же participant и unticketed context, сохранить привязки Messages. Проверить регрессию A → переписка → close → B → переписка, refresh, pagination и delivery action isolation. Lifecycle не менять.
- [x] 14.2 Отдельно проверить timestamps: выводить created_at/closed_at/messages.created_at в Europe/Moscow с пометкой «МСК», сохранить UTC в данных и таймерах; проверить переход суток и отсутствие мутаций при refresh.
