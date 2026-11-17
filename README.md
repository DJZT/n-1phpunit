**English** · [Українська](README_UA.md)

# phpunit-n-plus-one

A PHPUnit extension that listens to a Laravel application's SQL while feature tests run,
finds N+1 queries and, once the whole run is over, prints the worst offenders.

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

## Installation

```bash
composer require --dev djzt/phpunit-n-plus-one
```

Register the extension in your application's `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="NPlusOne\PHPUnit\NPlusOneExtension">
        <parameter name="threshold" value="3"/>
        <parameter name="limit" value="10"/>
    </bootstrap>
</extensions>
```

Nothing else has to change — neither your base `TestCase` nor `AppServiceProvider`.

## How it works

1. PHPUnit emits `Test\Prepared`, which arrives **after** `setUp()` — so the test's Laravel
   application is booted and sitting in `Container::getInstance()`.
2. The extension subscribes to `Illuminate\Database\Events\QueryExecuted` through the event
   dispatcher, which means every connection is observed, not only the default one.
3. Each query is tagged with a backtrace: the first frame **outside** `vendor/` is the place in
   your own code (controller, resource, blade view) that is running the loop.
4. Queries are grouped by SQL shape (literals become `?`, `in (1,2,3)` → `in (?)`) plus the call
   site. A group that repeats `threshold` times or more inside a single test becomes a finding.
5. `Test\Finished` closes the test; `Application\Finished` prints the ranked table.

The threshold is counted **within one test**. Fifty tests issuing the same query once each is
zero findings: that is just a suite exercising one endpoint, and nothing is added up. Only a loop
inside a single test is a finding. What does merge across tests is findings that already
qualified: if one call site produced an N+1 in three tests it appears as a single row, ranked by
its worst test (`87x`), with `total_queries` summing those tests. Tests that touched the same
place once are not part of the row.

The order of the report is deterministic: worst single test, then total volume, then call site.
Time is deliberately not part of the ranking — milliseconds differ between runs, and two runs'
reports could not be diffed against each other.

There are two kinds of finding:

| Kind | What it means |
| --- | --- |
| `N+1` | the same query shape with **different** parameters — the classic lazy `->load()` in a loop |
| `duplicate` | the same query with the same parameters **within one test** — a missing cache or memoisation |

Queries from `setUp()` (migrations, factories, seeders) never reach the report: the listener is
attached after the test has been prepared.

## Parameters

Every parameter can be set in `phpunit.xml` or overridden with an environment variable
`NPLUSONE_<NAME>` (dash → underscore), which is handy in CI:

```bash
NPLUSONE_THRESHOLD=2 NPLUSONE_REPORT_FILE=build/n-plus-one.json vendor/bin/phpunit
```

| Parameter | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | switch the extension off entirely |
| `threshold` | `3` | how many repeats of one query shape inside a test count as a problem (minimum 2) |
| `limit` | `10` | how many hotspots to show **in the console** — the file always keeps them all |
| `filter` | `~\bFeature\b~` | PCRE matched against the test id (`Tests\Feature\OrderTest::test_index`). An empty string analyses every test |
| `include-duplicates` | `true` | whether repeats with identical parameters are reported |
| `report-file` | — | path to the JSON report (the directory is created). Understands `{token}` and `{pid}` |
| `report-dir` | — | directory where each process writes its own report — what you want for `--parallel` |
| `console` | `true` | whether the table is printed at the end of the run (usually `false` in parallel) |
| `sql-max-length` | `160` | how far to truncate SQL in the console |
| `ignore-sql` | — | PCRE list separated by `\|\|\|`, applied to the normalised SQL |
| `base-path` | `getcwd()` | project root, so paths in the report are relative |
| `colors` | auto-detected | ANSI colours in the output |

Example: analyse every test, but stay quiet about housekeeping queries.

```xml
<bootstrap class="NPlusOne\PHPUnit\NPlusOneExtension">
    <parameter name="filter" value=""/>
    <parameter name="threshold" value="4"/>
    <parameter name="ignore-sql" value="~^select \* from &quot;?migrations~|||~^(savepoint|rollback|release)~"/>
    <parameter name="report-file" value="build/n-plus-one.json"/>
</bootstrap>
```

## Failing a single test

When a report at the end of the run is not enough and you want a red test on the spot:

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

The assertion looks only at the current test's queries and names the SQL and the call site in
its failure message.

## The JSON report

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

Worth keeping as a CI artefact and comparing between runs.

## When the application boots outside `setUp()`

Then there is nothing to attach to automatically — switch the listener on yourself once the
container is ready:

```php
use NPlusOne\PHPUnit\NPlusOne;

protected function setUp(): void
{
    parent::setUp();

    NPlusOne::listen($this->app);
}
```

## Parallel runs

`php artisan test --parallel` and paratest start one process per worker, each with its own copy
of the extension. A single `report-file` would be overwritten by whichever worker finished last,
so parallel runs have `report-dir`:

```xml
<extensions>
    <bootstrap class="NPlusOne\PHPUnit\NPlusOneExtension">
        <parameter name="threshold" value="3"/>
        <parameter name="report-dir" value="build/n-plus-one"/>
        <parameter name="console" value="false"/>
    </bootstrap>
</extensions>
```

Every worker writes its own file, named after the runner's token (`TEST_TOKEN`,
`UNIQUE_TEST_TOKEN`, `LARAVEL_PARALLEL_TESTING_TOKEN`), falling back to the pid when there is no
token:

```
build/n-plus-one/n-plus-one.1.json
build/n-plus-one/n-plus-one.2.json
...
```

`console=false` removes the eight tables that would otherwise interleave in the output: they
have to be read after the merge anyway, through the CLI below.

The merged verdict is the same as a sequential run's: the same hotspots, the same counts, the
same order and the same exit code. That is not a promise but a test —
`tests/Integration/SequentialVersusParallelTest.php` runs two interchangeable classes once in a
single process and once one-per-worker, then compares the merged reports.

Clean the directory before a run (`rm -rf build/n-plus-one`): if the previous run left more
files than there are workers now, the extra ones are merged in too. The CLI notices and warns
when the reports were written far apart in time, but it is better not to get there.

If you would rather keep a single file, `report-file` understands placeholders — and even
without them a worker appends its own suffix (`build/run.json` → `build/run.2.json`), so one
worker can never overwrite another's report:

```xml
<parameter name="report-file" value="build/n-plus-one-{token}.json"/>
```

## CI/CD: `vendor/bin/n-plus-one`

The package ships a CLI that merges every worker's report into one, ranks it and checks it
against a budget. The verdict comes back as an exit code, so any pipeline can act on it:

| Code | Meaning |
| --- | --- |
| `0` | within budget (or no budget set — then it is only a report) |
| `1` | budget exceeded |
| `2` | nothing to analyse, or bad usage |

```bash
# just look (can never fail)
vendor/bin/n-plus-one build/n-plus-one

# fail the build when one test fires more than 20 queries of one shape
vendor/bin/n-plus-one build/n-plus-one --max-queries=20
```

An argument may be a file, a directory or a glob; it defaults to `build/n-plus-one`. The merge
respects what the threshold means: `max_queries_in_one_test` stays the maximum for a single test
(it is not summed across workers), while `total_queries` shows the sum.

Budget rules — any one of them lets the command fail:

| Flag | Fails when |
| --- | --- |
| `--max-queries=N` | one test runs a query shape more than N times |
| `--max-hotspots=N` | the run has more than N hotspots |
| `--fail-on-any` | any hotspot is left after the baseline |
| `--baseline=FILE` | a new hotspot appeared, or a known one got worse |

The rest: `--write-baseline=FILE`, `--output=FILE` (the merged JSON as an artefact),
`--kind=all|n+1`, `--top=N`, `--format=text|json|github`, `--allow-empty`, `--no-color`. For the
full list, `vendor/bin/n-plus-one --help`.

### A ratchet through the baseline

A live project usually has dozens of hotspots on day one, and failing on all of them is
pointless. Record the current state and demand that it does not get worse:

```bash
vendor/bin/n-plus-one build/n-plus-one --write-baseline=.n-plus-one-baseline.json
git add .n-plus-one-baseline.json
```

From then on `--baseline=.n-plus-one-baseline.json` accepts everything recorded, but fails on a
new call site and on a counter that grew (`40x` before, `41x` now). Pay off some debt, rewrite
the baseline, and the bar comes down.

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

`--format=github` prints `::error` right on the line with the loop (the annotation shows up in
the PR diff), appends a table to `$GITHUB_STEP_SUMMARY` and sets the outputs `status`,
`hotspots`, `violations` and `worst_queries` for later steps. When the budget is not exceeded,
hotspots are annotated as `::warning` — visible, but not red.

### Any other CI

```yaml
n+1:
  script:
    - php artisan test --parallel
    - vendor/bin/n-plus-one build/n-plus-one --max-queries=20 --format=json --output=merged.json
  artifacts:
    when: always
    paths: [merged.json]
```

`--format=json` prints `status`, `summary`, `violations` and `hotspots` as one object — easy to
parse with your own script or to push into metrics.

## Limitations

* The only source of queries is Laravel/Illuminate (`illuminate/database`). Doctrine and plain
  PDO are not supported yet, but the collecting layer is separate (`src/Laravel`), so adding a
  driver is not hard.
* In a parallel run reports are written one per process and merged by the CLI
  (`report-dir` + `vendor/bin/n-plus-one`) — see the sections above.
* Capturing a backtrace on every query costs time, so the extension is meant to be on in tests,
  not in production.
* When the loop lives in a blade template, the call site is the *compiled* view under
  `storage/framework/views`, since that is the first frame outside `vendor/`. The count and the
  SQL are right; the path is the one you have to translate back yourself.

## Compatibility

| | Versions |
| --- | --- |
| PHP | 8.1 – 8.4 |
| PHPUnit | 10.5, 11, 12 |
| Laravel / Illuminate | 10, 11, 12 |

The extension is built on PHPUnit's event API (`PHPUnit\Runner\Extension\Extension`), which
arrived in PHPUnit 10, so **PHPUnit 9 is not supported**. Laravel 10 itself is: projects on
Laravel 10 with `phpunit/phpunit: ^10.1` (the default of the `laravel/laravel` 10.x skeleton)
work without caveats.

Queries are read through `Illuminate\Database\Events\QueryExecuted`, an event that is identical
in all three Laravel branches, so the package carries no version-specific code. The CI matrix
runs the tests on every combination above. The event's contract is pinned by
`test_it_understands_the_query_executed_event_of_the_installed_laravel`, which fails if some
Laravel version changes the fields of `QueryExecuted`.

One note on collecting: `DatabaseManager::listen()` does not exist in Laravel 10, 11 or 12
(`DB::listen()` is proxied through `__call` to the default connection), which is why the package
subscribes through the dispatcher and sees every connection.

## Development

```bash
composer install
vendor/bin/phpunit
```

`tests/Fixtures` is a miniature "application" (a container plus in-memory SQLite) with a real
`phpunit.xml`; `tests/Integration/EndToEndTest.php` runs PHPUnit against it in a separate
process and checks the actual report output. `tests/Integration/ParallelReportTest.php` runs it
as two workers with different `TEST_TOKEN` values and then judges them with the real
`bin/n-plus-one`, exit codes included. `tests/Integration/SequentialVersusParallelTest.php`
compares a sequential run with one split across workers and requires the same verdict.
