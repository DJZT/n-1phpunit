# phpunit-n-plus-one

PHPUnit-расширение, которое во время прогона feature-тестов слушает SQL-запросы Laravel-приложения,
находит N+1 и после всего прогона печатает топ самых тяжёлых мест.

```
==============================================================================
  N+1 query report
==============================================================================
  Top 10 of 23 hotspots (a query shape repeated 3+ times in one test)

 #1   87x    N+1         app/Http/Controllers/OrderController.php:31 (OrderController->index())
        select * from "users" where "id" = ? limit 1
        in Tests\Feature\OrderTest::test_index (87x) +4 more tests
        212 queries overall, 41.87 ms

 #2   12x    duplicate   app/Support/Settings.php:44 (Settings->get())
        select * from "settings" where "key" = ? limit 1
        in Tests\Feature\CheckoutTest::test_checkout (12x)
        30 queries overall, 3.11 ms
------------------------------------------------------------------------------
  4302 queries recorded in 318 tests; 27 tests affected.
```

## Установка

```bash
composer require --dev djzt/phpunit-n-plus-one
```

Регистрируем расширение в `phpunit.xml` приложения:

```xml
<extensions>
    <bootstrap class="NPlusOne\PHPUnit\NPlusOneExtension">
        <parameter name="threshold" value="3"/>
        <parameter name="limit" value="10"/>
    </bootstrap>
</extensions>
```

Больше ничего менять не нужно: ни базовый `TestCase`, ни `AppServiceProvider`.

## Как это работает

1. PHPUnit присылает событие `Test\Prepared` — оно приходит уже **после** `setUp()`, то есть
   Laravel-приложение теста поднято и лежит в `Container::getInstance()`.
2. Расширение вешает слушатель на `Illuminate\Database\Events\QueryExecuted` (через диспетчер
   событий, поэтому ловятся все соединения, а не только дефолтное).
3. Для каждого запроса снимается стек и берётся первый кадр **вне** `vendor/` — то самое место
   в коде приложения (контроллер, ресурс, blade), которое крутит цикл.
4. Запросы группируются по «форме» SQL (литералы заменяются на `?`, `in (1,2,3)` → `in (?)`)
   плюс место вызова. Группа, повторившаяся внутри одного теста `threshold` раз и больше,
   становится находкой.
5. По `Test\Finished` тест закрывается, по `Application\Finished` печатается сводный топ.

Порог считается **внутри одного теста**. Если пятьдесят тестов дёрнули один и тот же запрос
по разу — это ноль находок: сюита просто ходит в один эндпоинт, ничего не суммируется.
Находкой становится только цикл внутри одного теста. Через тесты складываются лишь уже
состоявшиеся находки: если одно и то же место дало N+1 в трёх тестах, оно попадёт в отчёт
одной строкой, ранжирование идёт по худшему тесту (`87x`), а `total_queries` показывает сумму
по этим тестам. Тесты, которые сходили по этому месту один раз, в строку не попадают.

Отличаются два вида находок:

| Вид | Что значит |
| --- | --- |
| `N+1` | одна и та же форма запроса с **разными** параметрами — классический ленивый `->load()` в цикле |
| `duplicate` | тот же запрос с теми же параметрами **в пределах одного теста** — не хватает кеша/мемоизации |

Запросы из `setUp()` (миграции, фабрики, сидеры) в отчёт не попадают — слушатель включается
после подготовки теста.

## Параметры

Любой параметр можно задать в `phpunit.xml` или перебить переменной окружения
`NPLUSONE_<ИМЯ>` (дефис → подчёркивание), что удобно в CI:

```bash
NPLUSONE_THRESHOLD=2 NPLUSONE_REPORT_FILE=build/n-plus-one.json vendor/bin/phpunit
```

| Параметр | По умолчанию | Описание |
| --- | --- | --- |
| `enabled` | `true` | полностью выключить расширение |
| `threshold` | `3` | сколько повторов одной формы запроса внутри теста считать проблемой (минимум 2) |
| `limit` | `10` | сколько мест показывать в топе |
| `filter` | `~\bFeature\b~` | PCRE по id теста (`Tests\Feature\OrderTest::test_index`). Пустая строка — анализировать все тесты |
| `include-duplicates` | `true` | показывать ли повторы с одинаковыми параметрами |
| `report-file` | — | путь к JSON-отчёту (каталог создаётся сам). Поддерживает `{token}` и `{pid}` |
| `report-dir` | — | каталог, куда каждый процесс пишет свой отчёт — то, что нужно для `--parallel` |
| `console` | `true` | печатать ли таблицу в конце прогона (в параллели обычно `false`) |
| `sql-max-length` | `160` | до скольких символов обрезать SQL в консоли |
| `ignore-sql` | — | список PCRE через `\|\|\|`, применяется к нормализованному SQL |
| `base-path` | `getcwd()` | корень проекта, чтобы пути в отчёте были относительными |
| `colors` | автоопределение | ANSI-цвета в выводе |

Пример: анализировать все тесты, но не шуметь на служебных запросах.

```xml
<bootstrap class="NPlusOne\PHPUnit\NPlusOneExtension">
    <parameter name="filter" value=""/>
    <parameter name="threshold" value="4"/>
    <parameter name="ignore-sql" value="~^select \* from &quot;?migrations~|||~^(savepoint|rollback|release)~"/>
    <parameter name="report-file" value="build/n-plus-one.json"/>
</bootstrap>
```

## Провалить конкретный тест

Если хочется не просто отчёт в конце, а красный тест прямо на месте:

```php
use NPlusOne\PHPUnit\Support\AssertsNoNPlusOneQueries;

final class OrderTest extends TestCase
{
    use AssertsNoNPlusOneQueries;

    public function test_orders_index_is_eager_loaded(): void
    {
        $this->get('/orders')->assertOk();

        $this->assertNoNPlusOneQueries();
    }
}
```

Ассерт смотрит только на запросы текущего теста и в сообщении показывает SQL и место вызова.

## JSON-отчёт

```json
{
    "generated_at": "2026-09-10T12:00:00+00:00",
    "threshold": 3,
    "summary": { "analysed_tests": 318, "recorded_queries": 4302, "affected_tests": 27, "hotspots": 23 },
    "hotspots": [
        {
            "kind": "N+1",
            "sql": "select * from \"users\" where \"id\" = ? limit 1",
            "origin": "app/Http/Controllers/OrderController.php:31",
            "max_queries_in_one_test": 87,
            "total_queries": 212,
            "total_time_ms": 41.87,
            "tests": { "Tests\\Feature\\OrderTest::test_index": 87 }
        }
    ]
}
```

Удобно складывать артефактом CI и сравнивать между прогонами.

## Если приложение поднимается не в `setUp()`

Тогда автопривязки не будет — включите слушатель руками, когда контейнер готов:

```php
use NPlusOne\PHPUnit\NPlusOne;

protected function setUp(): void
{
    parent::setUp();

    NPlusOne::listen($this->app);
}
```

## Параллельный прогон

`php artisan test --parallel` и paratest поднимают по процессу на воркер, и каждый
процесс со своим расширением. Один `report-file` они бы перезаписали друг другом,
поэтому для параллели есть `report-dir`:

```xml
<extensions>
    <bootstrap class="NPlusOne\PHPUnit\NPlusOneExtension">
        <parameter name="threshold" value="3"/>
        <parameter name="report-dir" value="build/n-plus-one"/>
        <parameter name="console" value="false"/>
    </bootstrap>
</extensions>
```

Каждый воркер пишет свой файл, имя берётся из токена раннера (`TEST_TOKEN`,
`UNIQUE_TEST_TOKEN`, `LARAVEL_PARALLEL_TESTING_TOKEN`), а если токена нет — из pid:

```
build/n-plus-one/n-plus-one.1.json
build/n-plus-one/n-plus-one.2.json
...
```

`console=false` убирает восемь таблиц, наезжающих друг на друга в выводе: читать их
всё равно надо после слияния, через CLI ниже.

Каталог стоит чистить перед прогоном (`rm -rf build/n-plus-one`): если от прошлого
запуска осталось больше файлов, чем воркеров сейчас, лишние попадут в слияние. CLI
это заметит и предупредит, если отчёты записаны с большим разбросом по времени, но
лучше не доводить.

Если хочется остаться на одном файле, `report-file` понимает подстановки — и, даже
без них, воркер сам добавит свой суффикс (`build/run.json` → `build/run.2.json`),
так что затереть чужой отчёт не получится:

```xml
<parameter name="report-file" value="build/n-plus-one-{token}.json"/>
```

## CI/CD: `vendor/bin/n-plus-one`

Пакет ставит CLI, который сливает отчёты всех воркеров в один, ранжирует и
сравнивает с бюджетом. Решение он отдаёт кодом возврата, так что реагировать
может любой pipeline:

| Код | Значение |
| --- | --- |
| `0` | в пределах бюджета (или бюджет не задан — тогда это просто отчёт) |
| `1` | бюджет превышен |
| `2` | анализировать нечего или ошибка в аргументах |

```bash
# просто посмотреть (никогда не падает)
vendor/bin/n-plus-one build/n-plus-one

# уронить сборку, если один тест делает больше 20 запросов одной формы
vendor/bin/n-plus-one build/n-plus-one --max-queries=20
```

Аргументом можно давать файл, каталог или glob; по умолчанию берётся
`build/n-plus-one`. Слияние уважает смысл порога: `max_queries_in_one_test`
остаётся максимумом по одному тесту (не суммируется между воркерами), а
`total_queries` показывает сумму.

Правила бюджета — любое из них включает способность падать:

| Флаг | Когда падает |
| --- | --- |
| `--max-queries=N` | один тест выполняет форму запроса больше N раз |
| `--max-hotspots=N` | в прогоне больше N мест |
| `--fail-on-any` | осталось хоть одно место (после вычета baseline) |
| `--baseline=FILE` | появилось новое место или известное стало хуже |

Остальное: `--write-baseline=FILE`, `--output=FILE` (слитый JSON артефактом),
`--kind=all|n+1`, `--top=N`, `--format=text|json|github`, `--allow-empty`,
`--no-color`. Полный список — `vendor/bin/n-plus-one --help`.

### Храповик через baseline

На живом проекте мест обычно сразу десятки, и падать на всех сразу бессмысленно.
Фиксируем текущее состояние и требуем, чтобы хуже не становилось:

```bash
vendor/bin/n-plus-one build/n-plus-one --write-baseline=.n-plus-one-baseline.json
git add .n-plus-one-baseline.json
```

Дальше в CI `--baseline=.n-plus-one-baseline.json` пропускает всё записанное, но
падает на новом месте и на выросшем счётчике (`40x` было, `41x` стало). Разобрали
долг — перезаписали baseline, и планка опустилась.

### GitHub Actions

```yaml
      - name: Tests
        run: php artisan test --parallel

      - name: N+1 budget
        id: nplusone
        run: vendor/bin/n-plus-one build/n-plus-one
             --baseline=.n-plus-one-baseline.json
             --format=github
             --output=build/n-plus-one-merged.json

      - uses: actions/upload-artifact@v4
        if: always()
        with:
          name: n-plus-one
          path: build/n-plus-one-merged.json
```

`--format=github` печатает `::error` прямо на строке с циклом (аннотация видна в
диффе PR), дописывает таблицу в `$GITHUB_STEP_SUMMARY` и выставляет outputs
`status`, `hotspots`, `violations`, `worst_queries` для следующих шагов. Когда
бюджет не нарушен, места помечаются как `::warning` — видно, но не красно.

### Любой другой CI

```yaml
n+1:
  script:
    - php artisan test --parallel
    - vendor/bin/n-plus-one build/n-plus-one --max-queries=20 --format=json --output=merged.json
  artifacts:
    when: always
    paths: [merged.json]
```

`--format=json` печатает `status`, `summary`, `violations` и `hotspots` одним
объектом — удобно разбирать своим скриптом или складывать в метрики.

## Ограничения

* Источник данных — только Laravel/Illuminate (`illuminate/database`). Doctrine и «голый» PDO
  пока не поддерживаются, но слой сбора вынесен отдельно (`src/Laravel`), так что добавить драйвер несложно.
* При параллельном прогоне отчёты пишутся по одному на процесс и сливаются CLI
  (`report-dir` + `vendor/bin/n-plus-one`) — см. разделы выше.
* Снятие стека на каждый запрос стоит времени, поэтому расширение имеет смысл держать
  включённым в тестах, а не в проде.

## Совместимость

| | Версии |
| --- | --- |
| PHP | 8.1 – 8.4 |
| PHPUnit | 10.5, 11, 12 |
| Laravel / Illuminate | 10, 11, 12 |

Расширение построено на событийном API PHPUnit (`PHPUnit\Runner\Extension\Extension`),
которое появилось в PHPUnit 10, поэтому **PHPUnit 9 не поддерживается**. Laravel 10 сам
по себе — поддерживается: проекты на Laravel 10 с `phpunit/phpunit: ^10.1` (дефолт скелета
`laravel/laravel` 10.x) работают без оговорок.

Запрос к БД читается через `Illuminate\Database\Events\QueryExecuted` — событие
одинаковое во всех трёх ветках Laravel, поэтому веткозависимого кода в пакете нет.
Матрица в CI гоняет тесты на всех комбинациях выше. Контракт события зафиксирован тестом
`test_it_understands_the_query_executed_event_of_the_installed_laravel` — он падёт, если в
какой-то версии Laravel поменяются поля `QueryExecuted`.

Отдельно про сбор: `DatabaseManager::listen()` в Laravel 10/11/12 не существует
(`DB::listen()` — это проксирование через `__call` в дефолтное соединение), поэтому пакет
подписывается на событие через диспетчер и видит все соединения.

## Разработка

```bash
composer install
vendor/bin/phpunit
```

`tests/Fixtures` — миниатюрное «приложение» (контейнер + SQLite in-memory) с настоящим
`phpunit.xml`; `tests/Integration/EndToEndTest.php` запускает по нему PHPUnit в отдельном
процессе и проверяет реальный вывод отчёта, а `tests/Integration/ParallelReportTest.php`
прогоняет его двумя воркерами с разными `TEST_TOKEN` и затем судит их настоящим
`bin/n-plus-one` — вместе с кодами возврата.
