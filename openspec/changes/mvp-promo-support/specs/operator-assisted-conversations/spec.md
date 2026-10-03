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
- **WHEN** приходит новый текст, отличный от `Проблема решена`, включая `Не решило` или `/start`
- **THEN** сообщение добавляется к существующему обращению и становится доступно оператору
- **AND** ticket переходит в `open`, `waiting_since` очищается, новый ticket не создаётся
- **AND** обычный AI-routing не вызывается
- **AND** новые кнопки не отправляются, прежний auto-close становится stale no-op

#### Scenario: Сообщение после закрытия
- **GIVEN** предыдущее обращение участника имеет статус `closed`
- **WHEN** приходит новое сообщение, отличное от текстов кнопок `Проблема решена` и `Не решило`
- **THEN** сообщение проходит обычную классификацию заново
- **AND** при необходимости может быть создано новое обращение

### Requirement: Очередь и история обращений
Аутентифицированный оператор MUST видеть очередь незакрытых обращений и хронологическую переписку выбранного обращения. Основная лента MUST содержать только Messages с ticket_id выбранного ticket и иметь cursor pagination по 50 сообщений. Сообщения предыдущих и последующих обращений того же participant MUST NOT попадать в неё; closed tickets MUST иметь независимые истории. Unticketed pre-escalation context временно MUST NOT отображаться. Чтение истории MUST NOT менять ticket_id сообщений, lifecycle или метрики. Действия ответа, retry и cancellation MUST оставаться привязаны к выбранному ticket. Self-registration, роли и управление операторами не требуются.

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

#### Scenario: Истории последовательных закрытых обращений
- **GIVEN** один participant создал ticket A, вёл переписку, закрыл его, затем создал ticket B, вёл переписку и закрыл его
- **WHEN** оператор открывает ticket A
- **THEN** основная лента содержит только сообщения A, без сообщений B
- **AND** при открытии B лента содержит только сообщения B, без сообщений A
- **AND** ticket_id существующих Messages остаются неизменными

#### Scenario: Сообщения до эскалации
- **GIVEN** участник получил ответ бота без ticket, затем попросил оператора
- **WHEN** оператор выбирает созданное обращение
- **THEN** история показывает просьбу позвать оператора и уведомление об эскалации, привязанные к этому ticket
- **AND** исходный вопрос и ответ бота с ticket_id = null не отображаются и сохраняют прежние привязки
- **AND** сообщения других участников и других tickets не отображаются, включая старые страницы истории
- **AND** delivery actions для Messages другого ticket недоступны из выбранного ticket

### Requirement: Часовой пояс дат операторской панели
Даты создания и закрытия ticket и timestamps сообщений MUST отображаться в Europe/Moscow с пометкой «МСК». Преобразование при отображении MUST NOT менять сохранённые UTC timestamps, глобальный timezone приложения или queue timers.

#### Scenario: Переход даты при отображении UTC timestamp
- **GIVEN** сообщение создано 02.10.2026 в 22:30 UTC
- **WHEN** оператор открывает его ticket
- **THEN** панель показывает 03.10.2026 01:30 МСК
- **AND** исходный timestamp остаётся 02.10.2026 22:30 UTC после отображения и refresh

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
- **THEN** Telegram API не вызывается и reply keyboard не отправляется

#### Scenario: Ответ оператора успешно доставлен
- **GIVEN** оператор отправляет ответ по открытому обращению
- **WHEN** Telegram API подтверждает отправку
- **THEN** исходящее сообщение сохранено и имеет состояние `sent`
- **AND** участник получает ответ с номером обращения и `ReplyKeyboardMarkup` с текстовыми кнопками `Проблема решена` и `Не решило`
- **AND** markup задаёт `resize_keyboard: true` и `one_time_keyboard: true`, inline-кнопок под сообщением нет
- **AND** ticket переходит из `open` в `waiting_for_user`, только при равенстве input_revision снимку operator reply; иначе остаётся `open`

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

### Requirement: Единственный текстовый механизм подтверждения
Система MUST обрабатывать feedback как обычные private text messages отправителя и выбирать только его собственный активный ticket. Inline callbacks MUST NOT менять состояние, сохранять сообщения или вызывать Telegram API; корректные legacy callback updates MUST подтверждаться HTTP 2xx как unsupported. Повторный text update MUST NOT повторять переход или сохранение сообщения.

#### Scenario: Feedback другого Telegram user
- **GIVEN** у владельца есть обращение `waiting_for_user`
- **WHEN** другой Telegram user отправляет `Проблема решена`
- **THEN** состояние обращения владельца не изменяется
- **AND** данные чужого обращения не раскрываются

#### Scenario: Legacy inline callback
- **GIVEN** участник нажимает inline-кнопку под старым ответом
- **WHEN** Telegram доставляет callback update
- **THEN** webhook возвращает HTTP 200 со статусом `ignored`
- **AND** ticket, адрес доставки, сообщения и jobs не меняются, callback acknowledgement не отправляется

#### Scenario: Повторный text feedback
- **GIVEN** текстовый feedback уже успешно сохранён и применён
- **WHEN** Telegram доставляет тот же update повторно
- **THEN** система подтверждает обработку без нового сообщения или перехода

#### Scenario: Feedback до фиксации successful delivery
- **GIVEN** operator reply сохранён, но successful delivery ещё не зафиксирована
- **WHEN** приходит любое новое входящее сообщение, в том числе между отказом Telegram и retry
- **THEN** оно сохраняется в том же ticket, увеличивает input_revision без AI и новых кнопок
- **AND** успешная финализация доставки оставляет ticket open без auto-close
- **AND** раннее «Проблема решена» остаётся в истории для ручного закрытия; подтверждение задним числом не применяется

#### Scenario: Ответ на клавиатуру вне активного обращения
- **GIVEN** у участника нет активного ticket
- **WHEN** приходит `Проблема решена` или `Не решило`
- **THEN** новое обращение и AI job не создаются

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
Для собственного обращения `waiting_for_user` текст `Проблема решена` MUST сохраняться в его истории, перевести ticket в `closed` и сохранить close reason `user_confirmed` без LLM и новых кнопок.

#### Scenario: User confirms solved
- **GIVEN** обращение участника имеет статус `waiting_for_user`
- **WHEN** владелец выбирает `Проблема решена`
- **THEN** обращение получает статус `closed`
- **AND** close reason равен `user_confirmed`

### Requirement: Возврат обращения оператору
Для собственного обращения `waiting_for_user` текст `Не решило` либо любой другой текст, кроме `Проблема решена`, MUST сохраняться в том же ticket и вернуть его в `open` без обычного AI-routing или новых кнопок.

#### Scenario: User selects unresolved
- **GIVEN** обращение участника имеет статус `waiting_for_user`
- **WHEN** владелец выбирает `Не решило`
- **THEN** обращение переходит в `open`
- **AND** текст сохраняется в истории, `waiting_since` очищается и старый auto-close становится stale no-op

#### Scenario: Clarification after unresolved
- **GIVEN** участник вернул обращение в `open`
- **WHEN** он отправляет уточняющее сообщение
- **THEN** сообщение добавляется в то же обращение
- **AND** становится доступно оператору без нового обычного AI-routing

### Requirement: Автоматическое закрытие
После успешной доставки ответа оператора при неизменной input_revision относительно снимка operator reply система MUST запланировать закрытие через конфигурируемый `TICKET_AUTO_CLOSE_HOURS`, равный 24 для MVP. Перед закрытием система MUST повторно проверить актуальное состояние обращения.

Auto-close MUST проверять ID ответа оператора и `waiting_since`, чтобы таймер предыдущего ответа не закрывал новый цикл, даже когда timestamps совпадают. Если input_revision изменилась относительно снимка operator reply, delayed close MUST NOT создаваться.

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

### Requirement: Revision lifecycle доставки
Ticket MUST оставаться open во время operator delivery. Каждое фактическое прикрепление participant message увеличивает tickets.input_revision один раз через TicketLifecycleService; operator reply сохраняет messages.operator_input_revision под ticket lock. После успешной доставки равные revisions разрешают open -> waiting_for_user и auto-close; при изменении ticket остаётся open без таймера. Это включает late AI attach ранее созданного сообщения и входящие между Telegram failure и retry. Message.id/created_at не определяют новизну input. Feedback «Проблема решена» / «Не решило» является action только в waiting_for_user; в open это input, увеличивающий revision, без подтверждения задним числом. Правило раннего feedback явно подтверждено пользователем 03.10.2026; прежние заявления о согласовании до этой даты не являлись подтверждением пользователя.

#### Scenario: Late AI attach во время доставки
- **GIVEN** вопрос создан раньше operator reply, но ещё не прикреплён
- **WHEN** его поздний AI-result прикрепляет вопрос к ticket во время Telegram delivery
- **THEN** input_revision увеличивается и устаревший AI-ответ не отправляется
- **AND** delivery фиксирует sent, но оставляет ticket open без auto-close

#### Scenario: Revision не изменилась
- **GIVEN** operator reply хранит текущую revision
- **WHEN** Telegram подтверждает отправку без новых participant attachments
- **THEN** ticket переходит из open в waiting_for_user и получает auto-close
