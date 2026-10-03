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

- У Telegram-участника может быть не более одного незакрытого обращения. Новые сообщения при статусе `open` или `waiting_for_user` добавляются в него без повторной обычной AI-маршрутизации; после `closed` следующее сообщение классифицируется заново.
- Интеграции с системами чеков, аккаунтов, победителей и доставки призов нет; бот не имитирует доступ к персональным данным акции.
- Операторская учётная запись создаётся заранее; self-registration, роли и управление операторами не входят в MVP.
- WebSocket и отдельный SPA не требуются; достаточно server-driven интерфейса на Livewire.
- Недоступность LLM, timeout, невалидный structured output и окончательный сбой обработки приводят к эскалации с причиной `llm_failure`.
- Полные runtime system prompts в PostgreSQL не сохраняются; единственный factual knowledge source — `promo-rules.md`.
- Обычный off-topic без ответа в правилах эскалируется по буквальному требованию исходного задания. Adversarial-запросы на раскрытие инструкций или фиктивное административное действие получают безопасный отказ.
- Для mixed request бот отвечает на подтверждённую правилами часть и одновременно эскалирует персональную или неизвестную часть. Это допущение подлежит пересмотру после ответа менеджера.
- Автозакрытие `waiting_for_user` выполняется через 24 часа по конфигурируемому `TICKET_AUTO_CLOSE_HOURS`.
- При `/start` бот предупреждает не отправлять банковские карты, пароли и SMS-коды, поскольку они не нужны для поддержки акции. Краткий повтор этого предупреждения при эскалации разрешён и рекомендован как presentation behavior, но не обязателен для каждого сообщения.

### Implementation decisions

- Входящий Telegram update идемпотентно сохраняется в PostgreSQL до обращения к LLM; AI-обработка запускается после commit через Laravel Queue с database driver.
- Один основной LLM request возвращает `decision/reason/answer/evidence`; PHP выполняет только deterministic validation, без отдельного verifier и builder. Валидный answer остаётся текстом для пользователя, trusted system context содержит московское время.
- LLM job ограничен тремя provider attempts с одним HTTP request на попытку и retry с backoff только для временных ошибок; invalid result сразу эскалируется; постоянные ошибки сразу передаются в существующий fallback. Сохранённое решение, ticket, исходящее сообщение и статистический результат защищаются от повторного создания при retry.
- Исходящие Telegram-сообщения имеют состояния `pending`, `sent`, `failed`; запись сообщения и успешная доставка являются разными фактами.
- Обращение использует статусы `open`, `waiting_for_user`, `closed` и причины закрытия `user_confirmed`, `auto_closed`, `operator_closed`.
- Callback `resolved` / `unresolved` проверяет владельца обращения по Telegram user и обрабатывается идемпотентно.
- Card-like sequences, явно обозначенные SMS/OTP-коды и пароли редактируются до persistence и до передачи в LLM, operator UI, Telegram quotes или application logs; raw unredacted body не сохраняется.
- Телефонный номер автоматически не редактируется, поскольку может идентифицировать аккаунт участника, но не включается в application logs и не повторяется без необходимости.
- При редактировании сохраняются только redacted text и, при необходимости, типы `payment_card`, `otp`, `password` без исходных значений; участник получает краткое уведомление о скрытии ненужных чувствительных данных.

### Границы MVP

В scope входят Telegram ingestion, grounded AI-support, эскалация, операторская очередь и история, ответы и delivery tracking, lifecycle обращения, callbacks, auto-close, статистика, evaluation runner для 25 сообщений и документация сдачи.

Вне scope остаются интеграции с промо-системами, поиск персональных данных, self-registration и роли операторов, WebSockets, Redis, RabbitMQ, Kafka, vector database, event sourcing, микросервисы, распознавание медиа и универсальный ассистент вне поддержки акции.

## Capabilities

### New Capabilities

- `grounded-participant-support`: grounded-ответы по правилам, unknown и participant-specific эскалация, mixed requests, structured LLM decision, безопасная обработка adversarial-ввода, sanitization и асинхронная AI-обработка.
- `operator-assisted-conversations`: обращения и история, операторская очередь, ответы и Telegram delivery, callback-действия, lifecycle, auto-close, manual close и идемпотентность Telegram updates.
- `support-statistics`: детерминированный расчёт bot resolved, escalated и среднего времени первого ответа оператора по сохранённым данным.

### Modified Capabilities

Нет: в проекте ещё нет основных capability specs.

## Impact

- Будущая реализация затронет Telegram webhook/callback endpoints, PostgreSQL domain tables, Laravel queue jobs, LLM и Telegram adapters, operator authentication, Livewire operator UI, statistics queries и evaluation command/report.
- Потребуются Docker Compose services для Laravel, PostgreSQL и queue worker без дополнительной инфраструктуры.
- Внешние границы MVP: Telegram Bot API и выбранный LLM provider; секреты поступают только через environment/configuration.
- Provisional semantics статистики: `bot resolved` — входящее сообщение, полностью обработанное ботом без ticket; `escalated` — созданный ticket; average operator response time — от создания ticket до первого ответа оператора, без ticket без ответа. Определения должны быть легко изменяемыми после уточнения менеджера.
