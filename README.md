## Первый запуск и оператор

1. Скопируйте `.env.example` в `.env`.
2. Укажите `OPERATOR_EMAIL` и собственный непустой `OPERATOR_PASSWORD` в `.env` (либо передайте их через environment). Email в примере предназначен для разработки; готового пароля и development fallback нет. Не коммитьте `.env`.
3. Запустите `docker compose up` (после изменения исходников образа — `docker compose up --build`).

Compose проверяет обязательные credentials до запуска контейнеров. PostgreSQL сначала проходит healthcheck; app выполняет migrations, затем `DatabaseSeeder` создаёт оператора, после чего запускает HTTP server. Queue worker стартует только после healthcheck app. Если credentials отсутствуют, Compose завершается с понятной ошибкой; seeder дополнительно проверяет email и непустой пароль.

Повторный запуск с тем же email не создаёт дубль и не меняет пароль существующего пользователя. В PostgreSQL пароль сохраняется только как hash через `User` cast `hashed`. Bootstrap не является механизмом смены пароля. После startup войдите в `/operator` с указанными credentials; self-registration отсутствует.

## Тесты на PostgreSQL

Стандартная команда полного suite (нужен Docker Compose):

```bash
docker compose -f compose.testing.yaml run --build --rm tests
```

Команда собирает образ с Composer dev-зависимостями, запускает отдельный PostgreSQL 17, ожидает healthcheck и выполняет `php artisan test --compact`. Connection — `pgsql`, database — `tg_promo_test`; эти значения также печатаются перед suite. Test Compose имеет отдельный project `tg-promo-tests`, не публикует PostgreSQL port и хранит БД в `tmpfs`, без development volumes, credentials и операторского bootstrap. `.env` не попадает в image; runtime использует безопасный `.env.example`. Копировать production/development credentials для тестов не нужно.

`phpunit.xml` закрепляет PostgreSQL и имя test database; development `DB_DATABASE` и `DB_URL` не могут переопределить их. Дополнительная проверка в `Tests\TestCase` выполняется до database refresh и отклоняет другую БД или cached development configuration. Существующие `LazilyRefreshDatabase` tests применяют migrations к test DB, включая PostgreSQL partial unique index; обычный `php artisan test` также использует PostgreSQL, если test DB доступна на настроенном host.

Остановить временную тестовую БД после работы:

```bash
docker compose -f compose.testing.yaml down
```

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Безопасность и ограничения AI

[Единственный prompt](resources/prompts/support-system.md) задаёт результат `decision/reason/answer/evidence`. Модель одновременно понимает вопрос, выбирает решение, формирует конкретный русский answer и цитаты из правил. Provider получает system instructions + правила + trusted московское время; sanitized participant text передаётся только в user role.

PHP проверяет JSON/schema, допустимые пары decision/reason, наличие rule ID и принадлежность непустой quote указанному пункту. `answer`/`mixed` требуют answer и evidence; `escalate` требует null answer и пустой evidence; `refuse/prompt_injection` допускает только безопасное «Я не могу выполнить этот запрос.» без фактов акции. Валидный answer сохраняется и отправляется как текст пользователя, без подмены полным правилом. Проверка реальности цитаты не доказывает правильность всех утверждений free-form answer; отдельного LLM verifier нет.

На попытку выполняется один LLM HTTP request с `LLM_TIMEOUT`. Штатная queue повторяет только transient errors, максимум три provider attempts на сообщение; даже `LLM_MAX_ATTEMPTS` выше трёх ограничивается тремя. Вложенного HTTP retry нет. Invalid result или permanent provider error сразу использует существующую safe escalation; после трёх transient failures применяется тот же fallback. Participant rate limits считают входящие запросы.

По умолчанию `LLM_TIMEOUT=120`, timeout AI job — 130 секунд, AI worker/listener — 150 секунд, `DB_QUEUE_RETRY_AFTER=180`. Reservation превышает execution timeout, чтобы долгий запрос не забрал второй worker. При изменении `LLM_TIMEOUT` согласуйте эти лимиты; уже поставленные в очередь jobs сохраняют timeout, заданный при создании. Три запроса, исчерпавшие по 120 секунд, и backoff 5 + 15 секунд займут около 6 минут 20 секунд без учёта ожидания в очереди и обработки результата.

Mixed request даёт grounded часть и эскалацию остатка. Обычный off-topic тоже эскалируется по буквальному требованию задания; prompt injection даёт безопасный отказ. Mixed/off-topic и определения метрик — допущения для уточнения менеджером. Модель не имеет административных tools и не подтверждает персональное состояние акции.

## Проверка ИИ и evaluation

[Evaluation всех 25 обращений](docs/evaluation.md) содержит результаты обработки и ручную оценку по правилам; [первый прогон](docs/evaluation-initial.md) сохраняет обнаруженные ошибки до уточнения prompt. Последний полный прогон 03.10.2026 выполнен на локальной `google/gemma-4-e4b` в LM Studio: 17 верно, 6 неверно, 2 спорно. В №9 модель ошибочно разрешила регистрацию чека 3 ноября, в №12 выдумала запрет на чек родственника; оба ответа прошли вторую LLM-проверку. Ещё пять cases завершились `llm_failure`. Эта конфигурация не продемонстрировала достаточного качества для пилота; отдельная проверка той же моделью не гарантирует семантическую корректность. Case 22 маскирован. Протокол: один isolated participant на вопрос, ProcessIncomingMessage выполняется штатным database Worker с tries/backoff/fallback; Telegram не вызывается, fixtures откатываются. Model result включает validation failures, infrastructure failure reason содержит HTTP/connection сбои; attempts записываются отдельно. Это отдельные cases с retry, не multi-turn conversation. Этот исторический прогон относится к прежнему pipeline со вторым verifier. Новый single-call pipeline проверен точечно на OpenRouter; сценарий №19 прошёл штатные webhook и workers, а последующий ручной повтор при LLM_TIMEOUT=120 завершился доставленным полным ответом без эскалации. Подробности и ограничения проверок сохранены в отчёте.

Повторный evaluation (требует реального LLM и test image, не отправляет Telegram и откатывает fixtures):

```bash
docker compose -f compose.testing.yaml run --rm tests php artisan migrate --force --no-interaction
docker compose -f compose.testing.yaml run --rm --env-from-file /absolute/path/private-llm.env -v "$PWD/docs:/app/evaluation-output" tests php artisan support:evaluate --output=/app/evaluation-output/evaluation.md --no-interaction
```

Создайте private env file вне репозитория с `LLM_ENDPOINT`, `LLM_MODEL`, `LLM_API_KEY`, `LLM_CONNECT_TIMEOUT`, `LLM_TIMEOUT`, ограничьте permissions и удалите после прогона. Команда перезаписывает отчёт с отметкой «требует проверки»: вручную оцените все строки. Не запускайте её одновременно с tests/refresh на этой БД. Eval использует rollback transaction вокруг LLM только в offline test run; записи/Telegram jobs не уходят в production.

Для LM Studio из Docker используйте `LLM_ENDPOINT=http://host.docker.internal:1234/v1/chat/completions`, ID загруженной модели и `LLM_RESPONSE_FORMAT=json_schema`: локальный API может отклонять стандартный для приложения `json_object`. Одна схема `support_decision` требует `decision`, `reason`, `answer`, `evidence`; JSON и связи decision/reason/answer/evidence, ID и quote membership проверяются в PHP. Режим `text` также доступен, но не гарантирует JSON со стороны provider.
