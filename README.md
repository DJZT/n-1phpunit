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

Отличаются два вида находок:

| Вид | Что значит |
| --- | --- |
| `N+1` | одна и та же форма запроса с **разными** параметрами — классический ленивый `->load()` в цикле |
| `duplicate` | тот же запрос с теми же параметрами — не хватает кеша/мемоизации |

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
| `report-file` | — | путь к JSON-отчёту (каталог создаётся сам) |
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

## Ограничения

* Источник данных — только Laravel/Illuminate (`illuminate/database`). Doctrine и «голый» PDO
  пока не поддерживаются, но слой сбора вынесен отдельно (`src/Laravel`), так что добавить драйвер несложно.
* При параллельном прогоне (`paratest`, `php artisan test --parallel`) каждый процесс печатает
  свой отчёт: сводить их нужно через `report-file`.
* Снятие стека на каждый запрос стоит времени, поэтому расширение имеет смысл держать
  включённым в тестах, а не в проде.

## Требования

PHP 8.2+, PHPUnit 10.5 / 11 / 12, Laravel 10 / 11 / 12.

## Разработка

```bash
composer install
vendor/bin/phpunit
```

`tests/Fixtures` — миниатюрное «приложение» (контейнер + SQLite in-memory) с настоящим
`phpunit.xml`; `tests/Integration/EndToEndTest.php` запускает по нему PHPUnit в отдельном
процессе и проверяет реальный вывод отчёта.
