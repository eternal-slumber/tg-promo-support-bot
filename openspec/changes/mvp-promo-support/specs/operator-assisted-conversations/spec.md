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
По принятому MVP-допущению участник MUST иметь не более одного обращения в статусе `open` или `resolved`. Новые сообщения при таком обращении MUST добавляться в его историю без обычной AI-маршрутизации.

#### Scenario: Сообщение при открытом обращении
- **GIVEN** у участника есть обращение `open`
- **WHEN** приходит новое текстовое сообщение
- **THEN** сообщение добавляется к существующему обращению
- **AND** новый ticket не создаётся
- **AND** обычный AI-routing не вызывается

#### Scenario: Сообщение в решённом обращении
- **GIVEN** у участника есть обращение `resolved`
- **WHEN** приходит любой новый текст, включая `/start`
- **THEN** сообщение добавляется к существующему обращению и становится доступно оператору
- **AND** ticket переходит в `open`, `resolved_since` очищается, новый ticket не создаётся
- **AND** обычный AI-routing не вызывается
- **AND** прежний auto-close становится stale no-op

#### Scenario: Сообщение после закрытия
- **GIVEN** предыдущее обращение участника имеет статус `closed`
- **WHEN** приходит любое новое текстовое сообщение
- **THEN** сообщение проходит обычную классификацию заново
- **AND** при необходимости может быть создано новое обращение

### Requirement: Очередь и история обращений
Аутентифицированный оператор MUST видеть очередь незакрытых обращений и хронологическую переписку выбранного обращения. Основная лента MUST содержать только Messages с ticket_id выбранного ticket и иметь cursor pagination по 50 сообщений. Сообщения предыдущих и последующих обращений того же participant MUST NOT попадать в неё; closed tickets MUST иметь независимые истории. Контекст до эскалации MUST отображаться в отдельном read-only блоке. Чтение истории MUST NOT менять ticket_id сообщений, lifecycle или метрики. Действия ответа, retry и cancellation MUST оставаться привязаны к выбранному ticket. Self-registration, роли и управление операторами не требуются.

Операторская панель MUST предоставлять фильтры `active`, `closed` и `all`. По умолчанию active-очередь содержит только обращения в статусах `open` и `resolved`; закрытые обращения доступны для просмотра с участником, временем создания и закрытия, причиной закрытия и историей сообщений. В закрытом обращении нельзя создать новый ответ, решить его или закрыть повторно; retry/cancel ранее созданного operator reply остаются доступны.

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
- **AND** исходный вопрос и доставленный ответ бота с ticket_id = null показаны отдельно в «Контекст до обращения» и сохраняют прежние привязки
- **AND** сообщения других участников и других tickets не отображаются, включая старые страницы истории
- **AND** delivery actions для Messages другого ticket недоступны из выбранного ticket

### Requirement: Фиксированный контекст до обращения
В момент создания ticket система MUST сохранить конкретные Message IDs для отдельного read-only блока «Контекст до обращения». Контекст MUST включать весь sanitized текстовый диалог participant/bot после закрытия предыдущего ticket до исходного сообщения эскалации включительно; для первого ticket нижняя граница — начало истории participant. Сообщения другого participant, предыдущих tickets, system/operator сообщения и недоставленные ответы бота MUST NOT включаться. Бот-ответ MUST относиться к вопросу внутри этой границы. Последние N сообщений без нижней границы MUST NOT использоваться. Старые Message MUST NOT перепривязываться к новому ticket; исходное сообщение эскалации сохраняет существующую привязку к созданному ticket. Поздняя доставка или новые сообщения MUST NOT менять сохранённый состав контекста. Основная лента MUST оставаться ticket-scoped.

#### Scenario: Вопрос и ответ до просьбы оператора
- **GIVEN** participant задал вопрос, получил доставленный ответ бота и попросил оператора
- **WHEN** создан ticket и оператор открывает его
- **THEN** отдельный контекст показывает вопрос, ответ бота и просьбу оператора в порядке сообщений
- **AND** вопрос и ответ остаются unticketed, основная лента содержит только сообщения созданного ticket

#### Scenario: Новый диалог после закрытия
- **GIVEN** ticket A закрыт, participant начал новый диалог и создал ticket B
- **WHEN** оператор открывает B
- **THEN** контекст начинается строго после события закрытия A
- **AND** сообщения A, его pre-ticket контекст и поздние ответы бота на старые вопросы отсутствуют

#### Scenario: Доставка после фиксации контекста
- **GIVEN** ответ бота ещё pending в момент создания ticket
- **WHEN** он позднее доставлен или participant продолжил переписку
- **THEN** сохранённые context_message_ids не меняются
- **AND** весь зафиксированный контекст доступен через отдельную pagination без ограничения последними N

#### Scenario: Исторические данные без точного события закрытия
- **GIVEN** предыдущий ticket создан до хранения событий закрытия
- **WHEN** создаётся новый ticket
- **THEN** сообщения не позже сохранённого closed_at исключаются консервативно
- **AND** существующие tickets без snapshot не получают выдуманный ретроспективный контекст

### Requirement: Явные границы и уведомление о закрытии
Панель MUST показывать системную границу «Обращение №N создано». Ручное и автоматическое закрытие MUST атомарно сохранять один системный Message и delivery job с текстом «Обращение №N закрыто оператором» либо «Обращение №N закрыто автоматически» и пояснением о новом диалоге для нового вопроса. Уведомление MUST относиться к закрытому ticket и доставляться через существующий worker. Только явно обозначенное системное событие закрытия MAY доставляться после closed; существующие guards для устаревших эскалаций, cancelled и уже sent сообщений MUST сохраняться. Lifecycle, AI pipeline, sanitizer, operator delivery и статистика MUST NOT меняться.

#### Scenario: Оператор закрывает обращение
- **WHEN** оператор явно закрывает open/resolved ticket
- **THEN** участник получает уведомление с номером обращения и способом «оператором»
- **AND** системное сообщение показано в истории этого ticket

#### Scenario: Закрытие по таймеру
- **WHEN** действующий таймер закрывает resolved ticket
- **THEN** участник получает уведомление с номером обращения и способом «автоматически»
- **AND** повторный или stale таймер не создаёт дополнительное уведомление

#### Scenario: Устаревшая эскалация после закрытия
- **GIVEN** обращение закрыто, его escalation notice остался в очереди
- **WHEN** worker выполняет старую delivery job и job уведомления о закрытии
- **THEN** эскалация не отправляется, уведомление о закрытии доставляется один раз
- **AND** ранее созданный operator reply остаётся deliverable, closed остаётся terminal

### Requirement: Часовой пояс дат операторской панели
Даты создания и закрытия ticket и timestamps сообщений MUST отображаться в Europe/Moscow с пометкой «МСК». Преобразование при отображении MUST NOT менять сохранённые UTC timestamps, глобальный timezone приложения или queue timers.

#### Scenario: Переход даты при отображении UTC timestamp
- **GIVEN** сообщение создано 02.10.2026 в 22:30 UTC
- **WHEN** оператор открывает его ticket
- **THEN** панель показывает 03.10.2026 01:30 МСК
- **AND** исходный timestamp остаётся 02.10.2026 22:30 UTC после отображения и refresh

### Requirement: Сохранение и доставка ответа оператора
Ответ оператора MUST быть сохранён до отправки в Telegram. Успешная запись в PostgreSQL MUST NOT считаться успешной доставкой; исходящее сообщение MUST иметь состояние `pending`, `sent` или `failed`.

Оператор MUST иметь возможность вести многошаговую последовательную переписку в open/resolved. Pending или failed operator reply MUST блокировать создание следующего до sent либо явной cancellation. Обычная отправка и successful delivery MUST NOT решать обращение или создавать auto-close. Новый operator reply в resolved MUST вернуть его в open. Retry MUST использовать прежний record. Manual/auto close MUST NOT отменять operator replies; недоставленные bot/system сообщения закрытого обращения, кроме нового явного уведомления о закрытии, MUST отменяться.

#### Scenario: Промежуточный ответ
- **GIVEN** ticket имеет статус open
- **WHEN** оператор сохраняет и доставляет ответ
- **THEN** ticket остаётся open без resolved_since или auto-close
- **AND** ответ содержит номер обращения и безопасную цитату без feedback keyboard

#### Scenario: Последовательные ответы
- **GIVEN** предыдущий operator reply имеет статус sent или cancelled
- **WHEN** оператор отправляет следующий ответ
- **THEN** новая pending Message и delivery job сохраняются
- **AND** ticket остаётся open

#### Scenario: Незавершённый ответ блокирует следующий
- **GIVEN** есть pending или failed operator reply
- **WHEN** оператор пытается создать следующий
- **THEN** панель показывает ошибку, новая Message/job не создаются
- **AND** для failed доступны явные «Повторить» и «Отменить»

#### Scenario: Продолжение решённого обращения оператором
- **GIVEN** ticket имеет статус resolved без unfinished operator reply
- **WHEN** оператор сохраняет новый ответ
- **THEN** ticket становится open до доставки и очищает resolved_since
- **AND** прежний auto-close становится stale no-op

#### Scenario: Telegram delivery failure
- **GIVEN** ответ сохранён как pending
- **WHEN** Telegram окончательно не принимает его
- **THEN** ответ остаётся в истории как failed
- **AND** delivery не меняет lifecycle ticket

#### Scenario: Send затем close до worker
- **GIVEN** operator reply сохранён как pending
- **WHEN** оператор закрывает ticket до начала worker
- **THEN** reply остаётся pending и доставляется как sent
- **AND** ticket остаётся closed, first response timestamp фиксируется по delivered_at

#### Scenario: Устаревшее уведомление об эскалации
- **GIVEN** уведомление осталось pending или failed после закрытия ticket
- **WHEN** delivery job проверяет его перед отправкой
- **THEN** уведомление становится cancelled без Telegram API call

#### Scenario: Отдельная отмена ответа
- **GIVEN** operator reply имеет pending или failed, ticket может быть closed
- **WHEN** оператор явно отменяет сообщение до начала delivery
- **THEN** оно становится cancelled, старые delivery jobs не отправляют его
- **AND** уже начавшаяся delivery не может быть отменена

### Requirement: Презентация сообщений обращения
Уведомление об эскалации и ответ оператора MUST явно содержать номер обращения. Короткая безопасная цитата исходной проблемы MAY быть показана, но длинный или чувствительный текст MUST NOT воспроизводиться полностью. Уведомление об эскалации MAY повторять краткий совет не отправлять карты, пароли и SMS-коды; это рекомендуемая presentation detail, а не обязательная часть каждого сообщения.

#### Scenario: Уведомление об эскалации
- **GIVEN** обращение создано
- **WHEN** система формирует Telegram-сообщение участнику
- **THEN** сообщение содержит номер обращения
- **AND** не раскрывает полную длинную историю или чувствительные данные

### Requirement: Отсутствие feedback механики
Feedback/reply keyboard MUST NOT отправляться или иметь отдельную lifecycle семантику. Прежние тексты кнопок MUST обрабатываться как обычный participant text. Legacy inline callbacks MUST оставаться ignored без изменения данных или Telegram API call.

Каждое исходящее Telegram message, включая ответ на `/start`, MUST содержать ReplyKeyboardRemove (`reply_markup={"remove_keyboard": true}`), чтобы убрать сохранённую старую клавиатуру при ближайшей успешной доставке. Удаление клавиатуры MUST NOT создавать отдельные сообщения, изменять delivery guards или lifecycle обращения.

#### Scenario: Старую клавиатуру удаляет ближайший ответ
- **GIVEN** Telegram-клиент участника сохранил кнопки «Проблема решена» и «Не решило»
- **WHEN** бот доставляет обычный ответ, системное уведомление или ответ оператора
- **THEN** sendMessage содержит только ReplyKeyboardRemove в reply_markup, без кнопок
- **AND** удаление клавиатуры не меняет status или resolved_since ticket

#### Scenario: Start удаляет старую клавиатуру
- **GIVEN** у участника сохранена старая feedback-клавиатура
- **WHEN** доставляется существующее предупреждение на `/start`
- **THEN** sendMessage содержит ReplyKeyboardRemove
- **AND** обычные guards и обработка `/start` сохраняются

#### Scenario: Прежний текст кнопки после закрытия
- **GIVEN** у participant нет active ticket
- **WHEN** приходит «Проблема решена» или «Не решило»
- **THEN** текст сохраняется и проходит обычную AI-маршрутизацию
- **AND** прежний closed ticket остаётся terminal

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
- **AND** ticket не переходит в `resolved`

### Requirement: Явное действие Отметить решённым
Только отдельное действие аутентифицированного оператора «Отметить решённым» MUST переводить open в resolved и атомарно создавать delayed auto-close. Действие MUST NOT создавать или отправлять сообщение; сохранённый reply не содержит resolve intent. Повторное решение resolved MUST NOT сдвигать таймер.

#### Scenario: Явное решение
- **GIVEN** ticket имеет статус open
- **WHEN** оператор нажимает «Отметить решённым»
- **THEN** ticket становится resolved и получает resolved_since и один delayed auto-close
- **AND** Messages и Telegram delivery не меняются

### Requirement: Автоматическое закрытие
Auto-close MUST запускаться только для resolved через configurable TICKET_AUTO_CLOSE_HOURS, default 24. Под ticket lock job MUST проверить status, точное resolved_since и истечение периода. Существующий timestamp MUST различать отдельные решения внутри одной секунды; новые revision/generation/marker механизмы MUST NOT добавляться.

#### Scenario: Resolved timeout
- **GIVEN** ticket остаётся resolved весь настроенный период
- **WHEN** его delayed job запускается
- **THEN** ticket становится closed с auto_closed

#### Scenario: Stale auto-close после reopen
- **GIVEN** resolved вернулся в open после participant message или operator reply
- **WHEN** старый таймер запускается
- **THEN** он не меняет status, closed_at или close_reason

#### Scenario: Stale auto-close после нового решения
- **GIVEN** ticket reopened и снова явно resolved с новым resolved_since
- **WHEN** запускается таймер предыдущего решения
- **THEN** он не закрывает новый цикл

### Requirement: Ручное закрытие и terminal state
Аутентифицированный оператор MUST закрывать open/resolved вручную с operator_closed. Closed MUST оставаться terminal: resolve/reopen/new reply/repeated close недоступны. Ранее созданный reply MUST сохранять возможность delivery/retry/cancel независимо от terminal ticket state.

#### Scenario: Manual close
- **GIVEN** ticket имеет open или resolved и pending operator reply
- **WHEN** оператор закрывает его
- **THEN** status становится closed и close_reason operator_closed
- **AND** reply сохраняет прежний delivery state

#### Scenario: Закрытие во время HTTP
- **GIVEN** HTTP operator reply уже начался
- **WHEN** ticket закрывается до фиксации delivery
- **THEN** successful result сохраняется как sent и first response timestamp фиксируется
- **AND** ticket остаётся closed без auto-close

#### Scenario: Late AI attach
- **GIVEN** во время прежнего AI-вызова появился active ticket
- **WHEN** поздний AI-result прикрепляет исходный вопрос
- **THEN** прежние stale AI side effects подавляются и resolved возвращается в open
- **AND** subsequent operator delivery не решает ticket
