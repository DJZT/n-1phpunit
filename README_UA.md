[English](README.md) · **Українська**

# phpunit-n-plus-one

Розширення для PHPUnit, яке під час прогону feature-тестів слухає SQL-запити Laravel-застосунку,
знаходить N+1 і після всього прогону друкує топ найважчих місць.

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

## Встановлення

```bash
composer require --dev djzt/phpunit-n-plus-one
```

Реєструємо розширення в `phpunit.xml` застосунку:

```xml
<extensions>
    <bootstrap class="NPlusOne\PHPUnit\NPlusOneExtension">
        <parameter name="threshold" value="3"/>
        <parameter name="limit" value="10"/>
    </bootstrap>
</extensions>
```

Більше нічого змінювати не потрібно: ні базовий `TestCase`, ні `AppServiceProvider`.

## Як це працює

1. PHPUnit надсилає подію `Test\Prepared` — вона приходить уже **після** `setUp()`, тобто
   Laravel-застосунок тесту піднятий і лежить у `Container::getInstance()`.
2. Розширення вішає слухача на `Illuminate\Database\Events\QueryExecuted` (через диспетчер
   подій, тому ловляться всі з'єднання, а не лише дефолтне).
3. Для кожного запиту знімається стек і береться перший кадр **поза** `vendor/` — те саме місце
   в коді застосунку (контролер, ресурс, blade), яке крутить цикл.
4. Запити групуються за «формою» SQL (літерали замінюються на `?`, `in (1,2,3)` → `in (?)`)
   плюс місце виклику. Група, що повторилася всередині одного тесту `threshold` разів і більше,
   стає знахідкою.
5. На `Test\Finished` тест закривається, на `Application\Finished` друкується зведений топ.

Поріг рахується **в межах одного тесту**. Якщо п'ятдесят тестів виконали один і той самий запит
по разу — це нуль знахідок: сюїта просто ходить в один ендпоінт, нічого не підсумовується.
Знахідкою стає лише цикл всередині одного тесту. Між тестами складаються тільки вже
наявні знахідки: якщо одне й те саме місце дало N+1 у трьох тестах, воно потрапить у звіт
одним рядком, ранжування йде за найгіршим тестом (`87x`), а `total_queries` показує суму
за цими тестами. Тести, які сходили в це місце один раз, у рядок не потрапляють.

Порядок у звіті детермінований: найгірший тест, далі загальна кількість, далі місце
виклику. Час у ранжування не входить — мілісекунди різні від прогону до прогону, і звіти
двох прогонів не можна було б порівняти дифом.

Розрізняються два види знахідок:

| Вид | Що означає |
| --- | --- |
| `N+1` | та сама форма запиту з **різними** параметрами — класичний лінивий `->load()` у циклі |
| `duplicate` | той самий запит з тими самими параметрами **в межах одного тесту** — бракує кешу/мемоізації |

Запити з `setUp()` (міграції, фабрики, сідери) у звіт не потрапляють — слухач включається
після підготовки тесту.

## Як виглядає звіт на справжньому N+1

Випадок, для якого розширення й зроблене: один запит проходить колекцією моделей і на кожній
торкається звʼязку. Фікстура нижче — `tests/Fixtures/Eloquent/PostBoard.php`, що стоїть замість
контролера, API-ресурсу чи blade-шаблону — рендерить дошку зі 100 постів:

```php
public function render(): array
{
    $rows = [];

    foreach (Post::all() as $post) {
        $locale = Setting::query()->where('key', 'locale')->first();

        $rows[] = [
            'title' => $post->title,
            'author' => $post->author->name,
            'comments' => $post->comments->count(),
            'locale' => $locale?->value,
        ];
    }

    return $rows;
}
```

Два feature-тести запитують цей екран, третій — його жадібно завантажену версію. Це справжній
вивід прогону, слово в слово:

```
==============================================================================
  N+1 query report
==============================================================================
  Top 3 of 3 hotspots (a query shape repeated 3+ times in one test)

 #1   100x    duplicate   tests/Fixtures/Eloquent/PostBoard.php:62 (PostBoard->render())
        select * from "settings" where "key" = ? limit 1
        in Tests\Feature\PostBoardTest::test_board_is_rendered (100x) +1 more test
        200 queries overall, 2.25 ms

 #2   100x    N+1         tests/Fixtures/Eloquent/PostBoard.php:66 (PostBoard->render())
        select * from "users" where "users"."id" = ? limit 1
        in Tests\Feature\PostBoardTest::test_board_is_rendered (100x) +1 more test
        200 queries overall, 2.28 ms

 #3   100x    N+1         tests/Fixtures/Eloquent/PostBoard.php:67 (PostBoard->render())
        select * from "comments" where "comments"."post_id" = ? and "comments"."post_id" is not null
        in Tests\Feature\PostBoardTest::test_board_is_rendered (100x) +1 more test
        200 queries overall, 4.60 ms

------------------------------------------------------------------------------
  604 queries recorded in 3 tests; 2 tests affected.
```

Як це читати:

* **Один рядок на проблему, а не на запит.** Три рядки того цикла дають три рядки звіту: автор
  на `:66`, коментарі на `:67` і налаштування на `:62`.
* **`100x` — це один запит.** Це найгірший окремий тест, тобто те, чого реально чекає користувач
  екрана, а не сума по сюїті. `200 queries overall` — сума, і вона відповідає на інше питання:
  скільки на цьому місці витрачає вся сюїта.
* **Винним призначається твій код.** Запити летять з глибини Eloquent, але місцем виклику
  вказано рядок у `render()`, який торкнувся звʼязку.
* **`+1 more test`** означає, що в це саме місце зайшов другий тест. Обидва перелічені в мапі
  `tests` у JSON-звіті, а головний лічильник залишається «на запит».
* **Жадібно завантаженого запиту тут немає.** Він робить два запити на той самий екран — нижче
  порога, тому це не знахідка. Саме тому `2 tests affected`, а не 3.
* **`duplicate` — це не N+1.** Налаштування читається з тим самим параметром щоразу, тож йому
  потрібен кеш, а не eager loading. Відфільтрувати справжні N+1 — `--kind=n+1` (або
  `include-duplicates=false`), і залишаться лише рядки #2 і #3.

Той самий прогін через CLI з бюджетом 20 запитів на запит:

```console
$ vendor/bin/n-plus-one build/n-plus-one --kind=n+1 --max-queries=20
  2 violations:
    - [max-queries] 100x in one test at tests/Fixtures/Eloquent/PostBoard.php:66 exceeds the budget of 20
    - [max-queries] 100x in one test at tests/Fixtures/Eloquent/PostBoard.php:67 exceeds the budget of 20
$ echo $?
1
```

І запис у JSON, що стоїть за рядком #2:

```json
{
    "kind": "N+1",
    "sql": "select * from \"users\" where \"users\".\"id\" = ? limit 1",
    "normalized_sql": "select * from \"users\" where \"users\".\"id\" = ? limit ?",
    "origin": "tests/Fixtures/Eloquent/PostBoard.php:66",
    "function": "PostBoard->render()",
    "connection": "default",
    "max_queries_in_one_test": 100,
    "total_queries": 200,
    "total_time_ms": 2.28,
    "tests": {
        "Tests\\Feature\\PostBoardTest::test_board_is_rendered": 100,
        "Tests\\Feature\\PostBoardTest::test_board_shows_authors": 100
    }
}
```

Цей приклад породжений `tests/Integration/EloquentLazyLoadingTest.php`, який перевіряє види,
рядки й лічильники вище, тож тихо застаріти він не може.

## Параметри

Будь-який параметр можна задати в `phpunit.xml` або перекрити змінною середовища
`NPLUSONE_<ІМ'Я>` (дефіс → підкреслення), що зручно в CI:

```bash
NPLUSONE_THRESHOLD=2 NPLUSONE_REPORT_FILE=build/n-plus-one.json vendor/bin/phpunit
```

| Параметр | Типово | Опис |
| --- | --- | --- |
| `enabled` | `true` | повністю вимкнути розширення |
| `threshold` | `3` | скільки повторів однієї форми запиту всередині тесту вважати проблемою (мінімум 2) |
| `limit` | `10` | скільки місць показувати в топі **у консолі** — у файл завжди пишуться всі |
| `filter` | `~\bFeature\b~` | PCRE за id тесту (`Tests\Feature\OrderTest::test_index`). Порожній рядок — аналізувати всі тести |
| `include-duplicates` | `true` | чи показувати повтори з однаковими параметрами |
| `report-file` | — | шлях до JSON-звіту (каталог створюється сам). Підтримує `{token}` і `{pid}` |
| `report-dir` | — | каталог, куди кожен процес пише свій звіт — саме те, що потрібно для `--parallel` |
| `console` | `true` | чи друкувати таблицю в кінці прогону (у паралелі зазвичай `false`) |
| `sql-max-length` | `160` | до скількох символів обрізати SQL у консолі |
| `ignore-sql` | — | список PCRE через `\|\|\|`, застосовується до нормалізованого SQL |
| `base-path` | `getcwd()` | корінь проєкту, щоб шляхи у звіті були відносними |
| `colors` | автовизначення | ANSI-кольори у виводі |

Приклад: аналізувати всі тести, але не шуміти на службових запитах.

```xml
<bootstrap class="NPlusOne\PHPUnit\NPlusOneExtension">
    <parameter name="filter" value=""/>
    <parameter name="threshold" value="4"/>
    <parameter name="ignore-sql" value="~^select \* from &quot;?migrations~|||~^(savepoint|rollback|release)~"/>
    <parameter name="report-file" value="build/n-plus-one.json"/>
</bootstrap>
```

## Завалити конкретний тест

Якщо хочеться не просто звіт у кінці, а червоний тест прямо на місці:

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

Ассерт дивиться лише на запити поточного тесту й у повідомленні показує SQL та місце виклику.

## JSON-звіт

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

Зручно складати артефактом CI і порівнювати між прогонами.

## Якщо застосунок піднімається не в `setUp()`

Тоді автоматичного підключення не буде — увімкніть слухача руками, коли контейнер готовий:

```php
use NPlusOne\PHPUnit\NPlusOne;

protected function setUp(): void
{
    parent::setUp();

    NPlusOne::listen($this->app);
}
```

## Паралельний прогін

`php artisan test --parallel` і paratest піднімають по процесу на воркер, і кожен
процес зі своїм розширенням. Один `report-file` вони б перезаписали один одному,
тому для паралелі є `report-dir`:

```xml
<extensions>
    <bootstrap class="NPlusOne\PHPUnit\NPlusOneExtension">
        <parameter name="threshold" value="3"/>
        <parameter name="report-dir" value="build/n-plus-one"/>
        <parameter name="console" value="false"/>
    </bootstrap>
</extensions>
```

Кожен воркер пише свій файл, ім'я береться з токена раннера (`TEST_TOKEN`,
`UNIQUE_TEST_TOKEN`, `LARAVEL_PARALLEL_TESTING_TOKEN`), а якщо токена немає — з pid:

```
build/n-plus-one/n-plus-one.1.json
build/n-plus-one/n-plus-one.2.json
...
```

`console=false` прибирає вісім таблиць, що налізають одна на одну у виводі: читати їх
усе одно треба після злиття, через CLI нижче.

Вердикт після злиття такий самий, як у послідовного прогону: ті самі місця, ті самі
лічильники, той самий порядок і той самий код виходу. Це не обіцянка, а тест —
`tests/Integration/SequentialVersusParallelTest.php` проганяє два однакові класи один раз
в одному процесі й один раз по воркеру на клас, після чого порівнює злиті звіти.

Каталог варто чистити перед прогоном (`rm -rf build/n-plus-one`): якщо від минулого
запуску залишилося більше файлів, ніж воркерів зараз, зайві потраплять у злиття. CLI
це помітить і попередить, якщо звіти записані з великим розкидом у часі, але
краще не доводити.

Якщо хочеться залишитися на одному файлі, `report-file` розуміє підстановки — і, навіть
без них, воркер сам додасть свій суфікс (`build/run.json` → `build/run.2.json`),
тож перезаписати чужий звіт не вийде:

```xml
<parameter name="report-file" value="build/n-plus-one-{token}.json"/>
```

## CI/CD: `vendor/bin/n-plus-one`

Пакет ставить CLI, який зливає звіти всіх воркерів в один, ранжує і
порівнює з бюджетом. Рішення він віддає кодом виходу, тому реагувати
може будь-який pipeline:

| Код | Значення |
| --- | --- |
| `0` | у межах бюджету (або бюджет не заданий — тоді це просто звіт) |
| `1` | бюджет перевищено |
| `2` | аналізувати нічого або помилка в аргументах |

```bash
# просто подивитися (ніколи не падає)
vendor/bin/n-plus-one build/n-plus-one

# завалити збірку, якщо один тест робить більше 20 запитів однієї форми
vendor/bin/n-plus-one build/n-plus-one --max-queries=20
```

Аргументом можна давати файл, каталог або glob; типово береться
`build/n-plus-one`. Злиття поважає сенс порога: `max_queries_in_one_test`
залишається максимумом за одним тестом (не підсумовується між воркерами), а
`total_queries` показує суму.

Правила бюджету — будь-яке з них вмикає здатність падати:

| Прапорець | Коли падає |
| --- | --- |
| `--max-queries=N` | один тест виконує форму запиту більше N разів |
| `--max-hotspots=N` | у прогоні більше N місць |
| `--fail-on-any` | залишилося хоч одне місце (після вирахування baseline) |
| `--baseline=FILE` | з'явилося нове місце або відоме стало гіршим |

Решта: `--write-baseline=FILE`, `--output=FILE` (злитий JSON артефактом),
`--kind=all|n+1`, `--top=N`, `--format=text|json|github`, `--allow-empty`,
`--no-color`. Повний список — `vendor/bin/n-plus-one --help`.

### Храповик через baseline

На живому проєкті місць зазвичай одразу десятки, і падати на всіх разом немає сенсу.
Фіксуємо поточний стан і вимагаємо, щоб гірше не ставало:

```bash
vendor/bin/n-plus-one build/n-plus-one --write-baseline=.n-plus-one-baseline.json
git add .n-plus-one-baseline.json
```

Далі в CI `--baseline=.n-plus-one-baseline.json` пропускає все записане, але
падає на новому місці та на вирослому лічильнику (`40x` було, `41x` стало). Розібрали
борг — перезаписали baseline, і планка опустилася.

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

`--format=github` друкує `::error` прямо на рядку з циклом (анотація видна в
дифі PR), дописує таблицю в `$GITHUB_STEP_SUMMARY` і виставляє outputs
`status`, `hotspots`, `violations`, `worst_queries` для наступних кроків. Коли
бюджет не порушено, місця позначаються як `::warning` — видно, але не червоно.

### Будь-який інший CI

```yaml
n+1:
  script:
    - php artisan test --parallel
    - vendor/bin/n-plus-one build/n-plus-one --max-queries=20 --format=json --output=merged.json
  artifacts:
    when: always
    paths: [merged.json]
```

`--format=json` друкує `status`, `summary`, `violations` і `hotspots` одним
об'єктом — зручно розбирати власним скриптом або складати в метрики.

## Обмеження

* Джерело даних — тільки Laravel/Illuminate (`illuminate/database`). Doctrine і «чистий» PDO
  поки не підтримуються, але шар збору винесений окремо (`src/Laravel`), тож додати драйвер нескладно.
* При паралельному прогоні звіти пишуться по одному на процес і зливаються CLI
  (`report-dir` + `vendor/bin/n-plus-one`) — див. розділи вище.
* Знімання стека на кожен запит коштує часу, тому розширення має сенс тримати
  увімкненим у тестах, а не в проді.
* Якщо цикл живе в blade-шаблоні, місцем виклику буде *скомпільований* шаблон у
  `storage/framework/views` — саме він перший кадр поза `vendor/`. Лічильник і SQL правильні,
  а шлях доведеться перекласти на вихідний `.blade.php` самостійно.

## Сумісність

| | Версії |
| --- | --- |
| PHP | 8.1 – 8.4 |
| PHPUnit | 10.5, 11, 12 |
| Laravel / Illuminate | 10, 11, 12 |

Розширення побудоване на подієвому API PHPUnit (`PHPUnit\Runner\Extension\Extension`),
який з'явився у PHPUnit 10, тому **PHPUnit 9 не підтримується**. Laravel 10 сам
по собі підтримується: проєкти на Laravel 10 з `phpunit/phpunit: ^10.1` (дефолт скелета
`laravel/laravel` 10.x) працюють без застережень.

Запит до БД читається через `Illuminate\Database\Events\QueryExecuted` — подія
однакова в усіх трьох гілках Laravel, тому гілкозалежного коду в пакеті немає.
Матриця в CI проганяє тести на всіх комбінаціях вище. Контракт події зафіксований тестом
`test_it_understands_the_query_executed_event_of_the_installed_laravel` — він впаде, якщо в
якійсь версії Laravel зміняться поля `QueryExecuted`.

Окремо про збір: `DatabaseManager::listen()` у Laravel 10/11/12 не існує
(`DB::listen()` — це проксування через `__call` у дефолтне з'єднання), тому пакет
підписується на подію через диспетчер і бачить усі з'єднання.

## Розробка

```bash
composer install
vendor/bin/phpunit
```

`tests/Fixtures` — мініатюрний «застосунок» (контейнер + SQLite in-memory) зі справжнім
`phpunit.xml`; `tests/Integration/EndToEndTest.php` запускає по ньому PHPUnit в окремому
процесі й перевіряє реальний вивід звіту. `tests/Integration/ParallelReportTest.php`
проганяє його двома воркерами з різними `TEST_TOKEN` і потім судить їх справжнім
`bin/n-plus-one` — разом з кодами виходу. `tests/Integration/SequentialVersusParallelTest.php`
порівнює послідовний прогін із розбитим на воркери й вимагає однакового вердикту.
