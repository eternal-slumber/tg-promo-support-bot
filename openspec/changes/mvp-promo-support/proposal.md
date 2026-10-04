# Proposal

## Why

Участники акции «Вкусная осень» задают повторяющиеся вопросы, которые можно закрывать по опубликованным правилам, а операторы должны подключаться только там, где правил недостаточно или нужны данные конкретного участника. Нужен небольшой, проверяемый MVP Telegram-поддержки с операторской панелью, надёжной эскалацией и измеримой статистикой.

## What Changes

### Исходные требования

- Telegram-бот принимает вопросы участников и отвечает только фактами, подтверждёнными `docs/assignment/promo-rules.md`.
- Если ответа в правилах нет или нужны данные конкретного участника, система создаёт обращение оператору и уведомляет участника.
- Оператор видит очередь и историю переписки, отвечает из веб-панели, доставляет ответ участнику через Telegram и закрывает обращение.
- Система показывает число сообщений, полностью закрытых ботом, число созданных обращений и среднее время первого ответа оператора.
- Решение использует PHP, Laravel, PostgreSQL, Telegram Bot API, Blade, Livewire, Tailwind CSS и Vite; запускается через `docker compose up`.
- Секреты не коммитятся, а используемые ботом prompt-файлы хранятся отдельно в репозитории.

### Принятые допущения MVP

- У Telegram-участника может быть не более одного незакрытого обращения. Новые сообщения при статусе `open` или `resolved` добавляются в него без повторной обычной AI-маршрутизации; после `closed` следующее сообщение классифицируется заново.
- Интеграции с системами чеков, аккаунтов, победителей и доставки призов нет; бот не имитирует доступ к персональным данным акции.
- Операторская учётная запись создаётся заранее; self-registration, роли и управление операторами не входят в MVP.
- WebSocket и отдельный SPA не требуются; достаточно server-driven интерфейса на Livewire.
- Недоступность LLM, timeout, невалидный structured output и окончательный сбой обработки приводят к эскалации с причиной `llm_failure`.
- Полные runtime system prompts в PostgreSQL не сохраняются; единственный factual knowledge source — `promo-rules.md`.
- Обычный off-topic без ответа в правилах эскалируется по буквальному требованию исходного задания. Adversarial-запросы на раскрытие инструкций или фиктивное административное действие получают безопасный отказ.
- Для mixed request бот отвечает на подтверждённую правилами часть и одновременно эскалирует персональную или неизвестную часть. Это допущение подлежит пересмотру после ответа менеджера.
- Автозакрытие `resolved` выполняется через 24 часа по конфигурируемому `TICKET_AUTO_CLOSE_HOURS`.
- При `/start` бот представляет поддержку акции «Вкусная осень», предлагает написать обычный текстовый вопрос без кнопки создания обращения, объясняет ответ по правилам и автоматическую передачу персональных вопросов оператору. Сообщение содержит 2–3 примера, предупреждение не отправлять номера карт, CVV/CVC, пароли и SMS/OTP-коды, ограничение фото/документов и ReplyKeyboardRemove. При redaction участник получает отдельное системное уведомление; escalation notice сообщает о передаче вопроса оператору и содержит номер обращения.

### Implementation decisions

- Входящий Telegram update идемпотентно сохраняется в PostgreSQL до обращения к LLM; AI-обработка запускается после commit через Laravel Queue с database driver.
- Один основной LLM request возвращает `decision/reason/answer/evidence`; PHP выполняет только deterministic validation, без отдельного verifier и builder. Валидный answer остаётся текстом для пользователя, trusted system context содержит московское время.
- LLM job ограничен тремя provider attempts с одним HTTP request на попытку и retry с backoff только для временных ошибок; invalid result сразу эскалируется; постоянные ошибки сразу передаются в существующий fallback. Сохранённое решение, ticket, исходящее сообщение и статистический результат защищаются от повторного создания при retry.
- Исходящие Telegram-сообщения имеют состояния `pending`, `sent`, `failed`, `cancelled`; запись сообщения и успешная доставка являются разными фактами.
- Обращение использует статусы `open`, `resolved`, `closed`; новые причины закрытия — `auto_closed` и `operator_closed`. Исторические `user_confirmed` сохраняются для просмотра.
- Ответ оператора не содержит feedback keyboard. Любое новое сообщение участника возвращает `resolved` в `open` без LLM; специальные тексты подтверждения не распознаются. Legacy inline callbacks игнорируются.
- Card-like sequences, явно обозначенные SMS/OTP-коды и пароли редактируются до persistence и до передачи в LLM, operator UI, Telegram quotes или application logs; raw unredacted body не сохраняется.
- Телефонный номер автоматически не редактируется, поскольку может идентифицировать аккаунт участника, но не включается в application logs и не повторяется без необходимости.
- При редактировании сохраняются только redacted text и, при необходимости, типы `payment_card`, `otp`, `password` без исходных значений; участник получает краткое уведомление о скрытии ненужных чувствительных данных.

### Границы MVP

В scope входят Telegram ingestion, grounded AI-support, эскалация, операторская очередь и история, ответы и delivery tracking, lifecycle обращения, auto-close, статистика, evaluation runner для 25 сообщений и документация сдачи.

Вне scope остаются интеграции с промо-системами, поиск персональных данных, self-registration и роли операторов, WebSockets, Redis, RabbitMQ, Kafka, vector database, event sourcing, микросервисы, распознавание медиа и универсальный ассистент вне поддержки акции.

## Capabilities

### New Capabilities

- `grounded-participant-support`: grounded-ответы по правилам, unknown и participant-specific эскалация, mixed requests, structured LLM decision, безопасная обработка adversarial-ввода, sanitization и асинхронная AI-обработка.
- `operator-assisted-conversations`: обращения и история, операторская очередь, ответы и Telegram delivery, lifecycle, auto-close, manual close и идемпотентность Telegram updates.
- `support-statistics`: детерминированный расчёт bot resolved, escalated и среднего времени первого ответа оператора по сохранённым данным.

### Modified Capabilities

Нет: в проекте ещё нет основных capability specs.

## Impact

- Реализация включает Telegram webhook endpoint, PostgreSQL domain tables, Laravel queue jobs, LLM и Telegram adapters, operator authentication, Livewire operator UI, statistics queries и evaluation command/report.
- Docker Compose запускает Laravel app, PostgreSQL и три queue workers без дополнительной инфраструктуры.
- Внешние границы MVP: Telegram Bot API и выбранный LLM provider; секреты поступают только через environment/configuration.
- Provisional semantics статистики: `bot resolved` — самостоятельный bot answer по правилам без ticket с `sent` и ненулевым `delivered_at`, исключая refuse; `escalated` — созданный ticket; average operator response time — от создания ticket до первой успешной delivery operator reply, без tickets без доставленного ответа. Счётчики подготовленных ответов также исключают refuse.

### Явное решение обращения

Обычный operator reply оставляет open и не запускает auto-close. Отдельное действие «Отметить решённым» переводит open в resolved и атомарно создаёт delayed auto-close. Новое participant message или новый operator reply возвращает resolved в open. Pending и failed operator reply блокируют создание следующего до sent либо явной отмены; failed имеет действия «Повторить» и «Отменить». Закрытие не отменяет созданные operator replies: они доставляются и после closed. Устаревшие bot/system уведомления закрытого ticket отменяются; явное новое уведомление о закрытии доставляется. Closed terminal. Таймер проверяет status, resolved_since и срок; новые revision/generation/marker механизмы не вводятся.

## Milestone 2: Контекст и границы обращения

Основная лента сохраняет строгий ticket_id scope. Отдельный read-only блок показывает конкретные sanitized Message IDs, зафиксированные при создании ticket: весь диалог после закрытия предыдущего обращения до исходной просьбы эскалации включительно, для первого — с начала истории. Включаются participant text и доставленные ответы бота на вопросы внутри этой границы; старые сообщения не перепривязываются, late delivery не расширяет контекст. Панель показывает «Обращение №N создано»; manual/auto close атомарно создаёт системное уведомление с номером и способом закрытия для истории и Telegram. AI pipeline, lifecycle, operator delivery semantics, sanitizer и статистика сохраняются. Новые поля: tickets.context_message_ids, messages.source_message_id и messages.ticket_event; новых таблиц нет.

Поздний AI-result по прежним правилам прикрепляет вопрос к активному ticket и подавляет устаревший ответ; reopening выполняется общим lifecycle service. AI contract, prompts и retry-policy не меняются.

Перед обновлением schema/application остановить webhook и workers, выполнить migrations новым кодом и перезапустить процессы. Текущая схема сохраняет историю, delivery state, pending/failed operator replies и first-response timestamps; status/resolved_since/deadline guards делают stale auto-close no-op.

Перед публичным пилотом используются Nginx/PHP-FPM, HTTPS proxy, выключенный debug и стабильный environment APP_KEY. Evaluation использует штатную job retry-policy с отдельными model result и infrastructure reason; sanitizer и AI pipeline этой доработкой не меняются.

## Evaluation сдаваемой версии

Финальный single-call evaluation 04.10.2026: **24 PASS / 0 PARTIAL / 1 FAIL** из 25 исходных обращений. FAIL №18 связан с неверным выводом о переносе непроверенных чеков. Исторический two-call evaluation предыдущей реализации и диагностические повторы сохранены в `docs/evaluation.md` и не являются оценкой сдаваемой версии.
