# Spec Delta

## Purpose

Capability обеспечивает полный минимальный lifecycle обращения: очередь и историю для оператора, безопасную доставку ответов через Telegram, действия участника и идемпотентное закрытие.

## ADDED Requirements

### Requirement: Аутентификация Telegram webhook
Webhook MUST проверять непустой configured secret token в заголовке `X-Telegram-Bot-Api-Secret-Token` безопасным сравнением до parsing, persistence и side effects.

#### Scenario: Подтверждённый Telegram request
- **GIVEN** webhook secret настроен
- **WHEN** запрос содержит совпадающий secret token
- **THEN** payload допускается к обычному ingestion и ownership validation

#### Scenario: Неподтверждённый request или spoofed callback
- **GIVEN** заголовок отсутствует, неверен либо webhook secret не настроен
- **WHEN** endpoint получает message или callback, даже с Telegram user ID владельца ticket
- **THEN** endpoint возвращает 403 без новых updates, participants, messages, tickets или jobs
- **AND** callback не меняет ticket и не вызывает Telegram API

### Requirement: Идемпотентный Telegram ingestion
Система MUST обработать каждый Telegram update не более одного раза. Повторная доставка update MUST NOT создавать новые сообщения, обращения, jobs, ответы или статистические результаты.

#### Scenario: Duplicate Telegram update
- **GIVEN** Telegram update уже был сохранён и принят к обработке
- **WHEN** webhook получает тот же update повторно
- **THEN** система возвращает успешное подтверждение приёма
- **AND** не создаёт повторных side effects

### Requirement: Одно незакрытое обращение участника
По принятому MVP-допущению участник MUST иметь не более одного обращения в статусе `open` или `waiting_for_user`. Новые сообщения при таком обращении MUST добавляться в его историю без обычной AI-маршрутизации.

#### Scenario: Сообщение при открытом обращении
- **GIVEN** у участника есть обращение `open`
- **WHEN** приходит новое текстовое сообщение
- **THEN** сообщение добавляется к существующему обращению
- **AND** новый ticket не создаётся
- **AND** обычный AI-routing не вызывается

#### Scenario: Сообщение при ожидании пользователя
- **GIVEN** у участника есть обращение `waiting_for_user`
- **WHEN** приходит новое текстовое сообщение без callback-действия
- **THEN** сообщение добавляется к существующему обращению и становится доступно оператору
- **AND** обычный AI-routing не вызывается

#### Scenario: Сообщение после закрытия
- **GIVEN** предыдущее обращение участника имеет статус `closed`
- **WHEN** приходит новое сообщение
- **THEN** сообщение проходит обычную классификацию заново
- **AND** при необходимости может быть создано новое обращение

### Requirement: Очередь и история обращений
Аутентифицированный оператор MUST видеть очередь незакрытых обращений и полную хронологическую историю сообщений выбранного обращения. Self-registration, роли и управление операторами не требуются.

Операторская панель MUST предоставлять фильтры `active`, `closed` и `all`. По умолчанию active-очередь содержит только обращения в статусах `open` и `waiting_for_user`; закрытые обращения доступны для просмотра с участником, временем создания и закрытия, причиной закрытия и историей сообщений. Закрытое обращение является read-only: оператор не может отправить в него ответ или закрыть его повторно.

#### Scenario: Просмотр закрытого обращения
- **GIVEN** оператор открыл фильтр закрытых обращений
- **WHEN** он выбирает ticket со статусом `closed`
- **THEN** панель показывает номер, участника, `created_at`, `closed_at`, `close_reason` и историю сообщений
- **AND** действия ответа и ручного закрытия недоступны

#### Scenario: Оператор открывает обращение
- **GIVEN** заранее созданный оператор аутентифицирован
- **WHEN** он выбирает обращение из очереди
- **THEN** панель показывает его номер, статус и историю участника, бота и оператора в хронологическом порядке

### Requirement: Сохранение и доставка ответа оператора
Ответ оператора MUST быть сохранён до отправки в Telegram. Успешная запись в PostgreSQL MUST NOT считаться успешной доставкой; исходящее сообщение MUST иметь состояние `pending`, `sent` или `failed`.

Для ticket MUST существовать не более одного незавершённого operator reply (`pending` или `failed`). Конкурентный submit MUST NOT создавать второй message; retry MUST использовать прежний message record. Manual close MUST быть запрещён до завершения operator reply. Delivery job MUST повторно проверить ticket до отправки и MUST NOT вызывать Telegram API для operator message закрытого ticket.

#### Scenario: Повторный submit и manual close при незавершённом ответе
- **GIVEN** ticket содержит operator reply в состоянии `pending` или `failed`
- **WHEN** оператор отправляет ещё один ответ либо вручную закрывает ticket
- **THEN** действие отклоняется без новой Message, job или изменения ticket status
- **AND** retry использует существующий message ID

#### Scenario: Устаревшая доставка ответа закрытого ticket
- **GIVEN** operator message ссылается на ticket, который уже `closed`
- **WHEN** delivery job повторно проверяет состояние перед отправкой
- **THEN** Telegram API не вызывается и callback-кнопки не отправляются

#### Scenario: Ответ оператора успешно доставлен
- **GIVEN** оператор отправляет ответ по открытому обращению
- **WHEN** Telegram API подтверждает отправку
- **THEN** исходящее сообщение сохранено и имеет состояние `sent`
- **AND** участник получает ответ с номером обращения и действиями `Проблема решена` и `Не решило мою проблему`
- **AND** ticket переходит из `open` в `waiting_for_user`

#### Scenario: Telegram delivery failure
- **GIVEN** ответ оператора сохранён со статусом `pending`
- **WHEN** Telegram API окончательно не принимает сообщение
- **THEN** запись ответа не удаляется
- **AND** delivery state становится `failed`
- **AND** ticket не переходит в `waiting_for_user` на основании недоставленного ответа

### Requirement: Презентация сообщений обращения
Уведомление об эскалации и ответ оператора MUST явно содержать номер обращения. Короткая безопасная цитата исходной проблемы MAY быть показана, но длинный или чувствительный текст MUST NOT воспроизводиться полностью. Уведомление об эскалации MAY повторять краткий совет не отправлять карты, пароли и SMS-коды; это рекомендуемая presentation detail, а не обязательная часть каждого сообщения.

#### Scenario: Уведомление об эскалации
- **GIVEN** обращение создано
- **WHEN** система формирует Telegram-сообщение участнику
- **THEN** сообщение содержит номер обращения
- **AND** не раскрывает полную длинную историю или чувствительные данные

### Requirement: Защищённые и идемпотентные callback-действия
Callback `resolved` или `unresolved` MUST применяться только к обращению, принадлежащему отправившему callback Telegram user. Повторное эквивалентное действие MUST NOT повторять переходы или side effects.

#### Scenario: Callback другого Telegram user
- **GIVEN** callback ссылается на обращение другого Telegram user
- **WHEN** система проверяет владельца обращения
- **THEN** состояние обращения не изменяется
- **AND** данные чужого обращения не раскрываются

#### Scenario: Повторный callback
- **GIVEN** callback участника уже был успешно применён
- **WHEN** Telegram доставляет тот же callback повторно
- **THEN** система подтверждает обработку без повторного изменения состояния

### Requirement: Ограничение длины Telegram presentation
Система MUST NOT передавать в Telegram `sendMessage` текст длиннее централизованного лимита 4096 Unicode символов. Operator reply MUST валидироваться до persistence с учётом итогового presentation overhead. Автоматические сообщения MAY обрезаться с явной пометкой, сохраняя полный sanitized текст в истории.

#### Scenario: Operator reply превышает доступный бюджет
- **GIVEN** ответ вместе с номером обращения и redacted quote превышает лимит Telegram
- **WHEN** оператор отправляет ответ
- **THEN** панель показывает понятную validation error
- **AND** новая Message и delivery job не создаются

#### Scenario: Слишком длинное автоматическое сообщение
- **GIVEN** bot/system outbound body длиннее лимита
- **WHEN** система строит Telegram presentation
- **THEN** отправляемый текст обрезается по Unicode символам с пометкой сокращения до допустимой длины
- **AND** сохранённый sanitized body не изменяется

#### Scenario: Defensive delivery guard
- **GIVEN** сохранённый operator reply формирует oversized payload
- **WHEN** delivery job обрабатывает сообщение
- **THEN** Telegram API не вызывается и message получает delivery state `failed`
- **AND** ticket не переходит в `waiting_for_user`

### Requirement: Подтверждение решения участником
Для собственного обращения `waiting_for_user` действие `Проблема решена` MUST перевести ticket в `closed` и сохранить close reason `user_confirmed`.

#### Scenario: User confirms solved
- **GIVEN** обращение участника имеет статус `waiting_for_user`
- **WHEN** владелец выбирает `Проблема решена`
- **THEN** обращение получает статус `closed`
- **AND** close reason равен `user_confirmed`

### Requirement: Возврат обращения оператору
Для собственного обращения `waiting_for_user` действие `Не решило мою проблему` MUST вернуть ticket в `open` и предложить участнику написать уточнение.

#### Scenario: User selects unresolved
- **GIVEN** обращение участника имеет статус `waiting_for_user`
- **WHEN** владелец выбирает `Не решило мою проблему`
- **THEN** обращение переходит в `open`
- **AND** бот просит написать уточнение

#### Scenario: Clarification after unresolved
- **GIVEN** участник вернул обращение в `open`
- **WHEN** он отправляет уточняющее сообщение
- **THEN** сообщение добавляется в то же обращение
- **AND** становится доступно оператору без нового обычного AI-routing

### Requirement: Автоматическое закрытие
После успешной доставки ответа оператора система MUST запланировать закрытие через конфигурируемый `TICKET_AUTO_CLOSE_HOURS`, равный 24 для MVP. Перед закрытием система MUST повторно проверить актуальное состояние обращения.

#### Scenario: Automatic close
- **GIVEN** обращение остаётся `waiting_for_user` в течение настроенного периода
- **WHEN** delayed close запускается
- **THEN** обращение переходит в `closed`
- **AND** close reason равен `auto_closed`

#### Scenario: Stale auto-close job after reopening
- **GIVEN** delayed close был создан для `waiting_for_user`
- **AND** участник позднее вернул обращение в `open`
- **WHEN** старый delayed job запускается
- **THEN** статус и close reason обращения не изменяются

### Requirement: Ручное закрытие оператором
Аутентифицированный оператор MUST иметь возможность закрыть незакрытое обращение вручную с close reason `operator_closed`.

#### Scenario: Manual close
- **GIVEN** обращение имеет статус `open` или `waiting_for_user`
- **WHEN** оператор подтверждает закрытие в панели
- **THEN** обращение переходит в `closed`
- **AND** close reason равен `operator_closed`
