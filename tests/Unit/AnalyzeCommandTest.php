<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Console\AnalyzeCommand;
use NPlusOne\PHPUnit\Tests\Support\TemporaryReports;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalyzeCommand::class)]
final class AnalyzeCommandTest extends TestCase
{
    use TemporaryReports;

    private string $output = '';

    protected function setUp(): void
    {
        $this->setUpTemporaryReports();
        $this->output = '';
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryReports();
    }

    public function test_it_reports_without_failing_when_no_budget_is_set(): void
    {
        $this->givenTwoWorkerReports();

        self::assertSame(AnalyzeCommand::EXIT_OK, $this->analyze([$this->directory]));
        self::assertStringContainsString('Analysed 2 report files', $this->output);
        self::assertStringContainsString('40x', $this->output);
        self::assertStringContainsString('No budget set', $this->output);
    }

    public function test_the_merged_worst_test_decides_the_query_budget(): void
    {
        $this->givenTwoWorkerReports();

        self::assertSame(AnalyzeCommand::EXIT_VIOLATIONS, $this->analyze([$this->directory, '--max-queries=20']));
        self::assertStringContainsString('1 violation:', $this->output);
        self::assertStringContainsString('[max-queries] 40x in one test', $this->output);

        $this->output = '';

        self::assertSame(AnalyzeCommand::EXIT_OK, $this->analyze([$this->directory, '--max-queries=40']));
        self::assertStringContainsString('Within budget.', $this->output);
    }

    public function test_options_can_be_spelled_with_a_space(): void
    {
        $this->givenTwoWorkerReports();

        self::assertSame(AnalyzeCommand::EXIT_VIOLATIONS, $this->analyze([$this->directory, '--max-queries', '20']));
    }

    public function test_the_analyze_verb_is_optional(): void
    {
        $this->givenTwoWorkerReports();

        self::assertSame(AnalyzeCommand::EXIT_OK, $this->analyze(['analyze', $this->directory]));
        self::assertStringContainsString('Analysed 2 report files', $this->output);
    }

    public function test_duplicates_can_be_left_out_of_the_analysis(): void
    {
        $this->writeReport('one.json', [
            'hotspots' => [
                $this->hotspot(tests: ['Tests\Feature\A::test_a' => 30]),
                $this->hotspot(
                    tests: ['Tests\Feature\B::test_b' => 9],
                    overrides: ['kind' => Detection::KIND_DUPLICATE, 'origin' => 'app/Support/Settings.php:44'],
                ),
            ],
        ]);

        self::assertSame(AnalyzeCommand::EXIT_VIOLATIONS, $this->analyze([$this->directory, '--max-hotspots=1']));

        $this->output = '';

        self::assertSame(AnalyzeCommand::EXIT_OK, $this->analyze([$this->directory, '--max-hotspots=1', '--kind=n+1']));
        self::assertStringNotContainsString('Settings.php', $this->output);
    }

    public function test_a_baseline_can_be_written_and_then_used(): void
    {
        $this->givenTwoWorkerReports();
        $baseline = $this->path('baseline.json');

        self::assertSame(AnalyzeCommand::EXIT_OK, $this->analyze([$this->directory, '--write-baseline=' . $baseline]));
        self::assertFileExists($baseline);
        self::assertStringContainsString('Baseline written to', $this->output);

        $this->output = '';

        self::assertSame(
            AnalyzeCommand::EXIT_OK,
            $this->analyze([$this->directory, '--baseline=' . $baseline, '--fail-on-any']),
            'everything in the baseline is accepted, even with --fail-on-any',
        );
        self::assertStringContainsString('Baseline: 1 known hotspot, 0 new or worse.', $this->output);
    }

    public function test_a_new_hotspot_fails_against_a_baseline(): void
    {
        $this->givenTwoWorkerReports();
        $baseline = $this->path('baseline.json');
        $this->analyze([$this->directory, '--write-baseline=' . $baseline]);

        $this->writeReport('n-plus-one.3.json', [
            'hotspots' => [$this->hotspot(
                tests: ['Tests\Feature\CartTest::test_totals' => 6],
                overrides: ['origin' => 'app/Support/Cart.php:12'],
            )],
        ]);

        $this->output = '';

        self::assertSame(AnalyzeCommand::EXIT_VIOLATIONS, $this->analyze([$this->directory, '--baseline=' . $baseline]));
        self::assertStringContainsString('New N+1 hotspot', $this->output);
        self::assertStringContainsString('app/Support/Cart.php:12', $this->output);
    }

    public function test_the_json_format_carries_the_verdict(): void
    {
        $this->givenTwoWorkerReports();

        self::assertSame(AnalyzeCommand::EXIT_VIOLATIONS, $this->analyze([$this->directory, '--format=json', '--max-queries=10']));

        /** @var array{status: string, budget_active: bool, summary: array<string, int>, violations: list<array<string, mixed>>, hotspots: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($this->output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('violations', $decoded['status']);
        self::assertTrue($decoded['budget_active']);
        self::assertSame(18, $decoded['summary']['analysed_tests']);
        self::assertSame(40, $decoded['summary']['worst_queries_in_one_test']);
        self::assertCount(1, $decoded['violations']);
        self::assertSame('max-queries', $decoded['violations'][0]['rule']);
        self::assertSame('app/Http/Controllers/OrderController.php:31', $decoded['violations'][0]['origin']);
        self::assertSame(47, $decoded['hotspots'][0]['total_queries']);
    }

    public function test_the_github_format_annotates_and_fills_in_the_step_outputs(): void
    {
        $this->givenTwoWorkerReports();
        $summary = $this->path('summary.md');
        $outputs = $this->path('outputs.txt');

        $exit = $this->analyze(
            [$this->directory, '--format=github', '--max-queries=10', '--no-color'],
            ['GITHUB_STEP_SUMMARY' => $summary, 'GITHUB_OUTPUT' => $outputs],
        );

        self::assertSame(AnalyzeCommand::EXIT_VIOLATIONS, $exit);
        self::assertStringContainsString(
            '::error file=app/Http/Controllers/OrderController.php,line=31,title=N+1 query budget::',
            $this->output,
        );

        self::assertStringContainsString('### N+1 query report', (string) file_get_contents($summary));
        self::assertStringContainsString('| 40x | N+1 |', (string) file_get_contents($summary));

        $written = (string) file_get_contents($outputs);
        self::assertStringContainsString('status=violations', $written);
        self::assertStringContainsString('hotspots=1', $written);
        self::assertStringContainsString('violations=1', $written);
        self::assertStringContainsString('worst_queries=40', $written);
    }

    public function test_a_clean_run_is_annotated_as_a_warning_not_an_error(): void
    {
        $this->givenTwoWorkerReports();

        self::assertSame(AnalyzeCommand::EXIT_OK, $this->analyze([$this->directory, '--format=github', '--no-color']));
        self::assertStringContainsString('::warning file=app/Http/Controllers/OrderController.php,line=31', $this->output);
        self::assertStringNotContainsString('::error', $this->output);
    }

    public function test_the_merged_report_can_be_written_for_ci_to_keep(): void
    {
        $this->givenTwoWorkerReports();
        $merged = $this->path('merged.json');

        self::assertSame(AnalyzeCommand::EXIT_OK, $this->analyze([$this->directory, '--output=' . $merged]));

        /** @var array{summary: array<string, int>, hotspots: list<array<string, mixed>>} $decoded */
        $decoded = json_decode((string) file_get_contents($merged), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $decoded['summary']['hotspots']);
        self::assertSame(40, $decoded['hotspots'][0]['max_queries_in_one_test']);
        self::assertSame(47, $decoded['hotspots'][0]['total_queries']);
    }

    public function test_it_warns_about_reports_left_over_from_an_earlier_run(): void
    {
        $this->writeReport('n-plus-one.1.json', [
            'generated_at' => date(DATE_ATOM, time() - 7200),
            'hotspots' => [$this->hotspot(tests: ['Tests\\Feature\\A::test_a' => 4])],
        ]);
        $this->writeReport('n-plus-one.2.json', [
            'hotspots' => [$this->hotspot(tests: ['Tests\\Feature\\B::test_b' => 4])],
        ]);

        self::assertSame(AnalyzeCommand::EXIT_OK, $this->analyze([$this->directory]));
        self::assertStringContainsString('written 120 minutes apart', $this->output);
    }

    public function test_reports_of_one_run_are_not_called_stale(): void
    {
        $this->givenTwoWorkerReports();

        self::assertSame(AnalyzeCommand::EXIT_OK, $this->analyze([$this->directory]));
        self::assertStringNotContainsString('earlier run', $this->output);
    }

    public function test_missing_reports_fail_loudly_by_default(): void
    {
        self::assertSame(AnalyzeCommand::EXIT_USAGE, $this->analyze([$this->path('nope')]));
        self::assertStringContainsString('No report found at', $this->output);
        self::assertStringContainsString('--allow-empty', $this->output);
    }

    public function test_missing_reports_can_be_tolerated_on_purpose(): void
    {
        self::assertSame(AnalyzeCommand::EXIT_OK, $this->analyze([$this->path('nope'), '--allow-empty']));
        self::assertStringContainsString('No N+1 reports to analyse', $this->output);
    }

    public function test_an_unknown_option_is_a_usage_error(): void
    {
        self::assertSame(AnalyzeCommand::EXIT_USAGE, $this->analyze(['--nope']));
        self::assertStringContainsString('Unknown option "--nope"', $this->output);
    }

    public function test_a_non_numeric_budget_is_a_usage_error(): void
    {
        self::assertSame(AnalyzeCommand::EXIT_USAGE, $this->analyze(['--max-queries=lots']));
        self::assertStringContainsString('needs a whole number', $this->output);
    }

    public function test_an_unknown_format_is_a_usage_error(): void
    {
        self::assertSame(AnalyzeCommand::EXIT_USAGE, $this->analyze(['--format=xml']));
        self::assertStringContainsString('Unknown --format', $this->output);
    }

    public function test_help_explains_the_exit_codes(): void
    {
        self::assertSame(AnalyzeCommand::EXIT_OK, $this->analyze(['--help']));
        self::assertStringContainsString('Exit codes: 0 within budget, 1 budget exceeded', $this->output);
        self::assertStringContainsString('--baseline=FILE', $this->output);
    }

    private function givenTwoWorkerReports(): void
    {
        $this->writeReport('n-plus-one.1.json', [
            'summary' => ['analysed_tests' => 10, 'recorded_queries' => 300, 'affected_tests' => 1, 'hotspots' => 1],
            'hotspots' => [$this->hotspot(tests: ['Tests\Feature\OrderTest::test_index' => 40])],
        ]);
        $this->writeReport('n-plus-one.2.json', [
            'summary' => ['analysed_tests' => 8, 'recorded_queries' => 120, 'affected_tests' => 1, 'hotspots' => 1],
            'hotspots' => [$this->hotspot(tests: ['Tests\Feature\OrderTest::test_show' => 7])],
        ]);
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     */
    private function analyze(array $argv, array $env = []): int
    {
        $command = new AnalyzeCommand(
            function (string $text): void {
                $this->output .= $text;
            },
            $env + ['NO_COLOR' => '1'],
        );

        return $command->run($argv);
    }
}
