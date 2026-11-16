<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Console;

use NPlusOne\PHPUnit\Analysis\Aggregator;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Hotspot;
use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Report\ConsoleReporter;
use NPlusOne\PHPUnit\Report\JsonReporter;
use NPlusOne\PHPUnit\Report\Statistics;
use Throwable;

/**
 * Merges the JSON reports of a test run — one per process under a parallel
 * runner — and turns them into something a pipeline can act on.
 *
 * Exit codes: 0 within budget, 1 budget exceeded, 2 nothing to analyse or bad
 * usage. With no budget rule the command only reports, and exits 0.
 */
final class AnalyzeCommand
{
    public const EXIT_OK = 0;
    public const EXIT_VIOLATIONS = 1;
    public const EXIT_USAGE = 2;

    public const DEFAULT_PATH = 'build/n-plus-one';

    /**
     * Workers of one run finish within seconds of each other, so a wider
     * spread than this means the directory was not cleaned between runs.
     */
    private const STALE_SPREAD_SECONDS = 600;

    private const WITH_VALUE = [
        '--max-queries',
        '--max-hotspots',
        '--baseline',
        '--write-baseline',
        '--top',
        '--format',
        '--output',
        '--kind',
    ];

    /** @var callable(string): void */
    private $out;

    /** @var array<string, string> */
    private array $env;

    /**
     * @param array<string, string>|null $env defaults to the real environment
     */
    public function __construct(?callable $out = null, ?array $env = null)
    {
        $this->out = $out ?? static function (string $text): void {
            if (defined('STDOUT')) {
                fwrite(STDOUT, $text);

                return;
            }

            print $text;
        };
        $this->env = $env ?? array_filter(getenv(), 'is_string');
    }

    /**
     * @param list<string> $argv arguments without the program name
     */
    public function run(array $argv): int
    {
        try {
            $arguments = $this->parse($argv);
        } catch (LoadFailure $failure) {
            $this->line('Error: ' . $failure->getMessage());
            $this->line('Run "n-plus-one --help" for usage.');

            return self::EXIT_USAGE;
        }

        if ($arguments['help']) {
            $this->out(self::usage() . PHP_EOL);

            return self::EXIT_OK;
        }

        $colors = $arguments['colors'] ?? $this->supportsColors();

        try {
            $reports = (new ReportLoader())->load($arguments['paths']);
            $baseline = $arguments['baseline'] === null ? null : Baseline::load($arguments['baseline']);
        } catch (LoadFailure $failure) {
            if ($arguments['allowEmpty']) {
                $this->line('No N+1 reports to analyse: ' . $failure->getMessage());

                return self::EXIT_OK;
            }

            $this->line('Error: ' . $failure->getMessage());
            $this->line('Point the command at the report-dir the extension writes, or pass --allow-empty.');

            return self::EXIT_USAGE;
        }

        $aggregator = new Aggregator();
        $aggregator->addAll($this->keep($reports->detections, $arguments['kind']));

        $all = $aggregator->top(PHP_INT_MAX);
        $statistics = new Statistics(
            analysedTests: $reports->analysedTests,
            recordedQueries: $reports->recordedQueries,
            affectedTests: $aggregator->affectedTestCount(),
            hotspots: $aggregator->count(),
        );

        if ($arguments['writeBaseline'] !== null) {
            return $this->writeBaseline($arguments['writeBaseline'], $all);
        }

        $budget = new Budget(
            maxQueries: $arguments['maxQueries'],
            maxHotspots: $arguments['maxHotspots'],
            failOnAny: $arguments['failOnAny'],
            baseline: $baseline,
        );

        $violations = $budget->violations($all);
        $newOrWorse = count($budget->unaccepted($all));
        $top = array_slice($all, 0, $arguments['top']);

        if ($arguments['output'] !== null && !$this->writeMerged($arguments['output'], $reports->threshold, $all, $statistics)) {
            return self::EXIT_USAGE;
        }

        match ($arguments['format']) {
            'json' => $this->renderJson($reports, $statistics, $all, $violations, $budget),
            'github' => $this->renderGithub($reports, $statistics, $top, $violations, $budget, $colors, $reports->threshold, $newOrWorse),
            default => $this->renderText($reports, $statistics, $top, $violations, $budget, $colors, $reports->threshold, $newOrWorse),
        };

        return $violations === [] ? self::EXIT_OK : self::EXIT_VIOLATIONS;
    }

    /**
     * @param list<Detection> $detections
     *
     * @return list<Detection>
     */
    private function keep(array $detections, string $kind): array
    {
        if ($kind !== Detection::KIND_N_PLUS_ONE) {
            return $detections;
        }

        return array_values(array_filter(
            $detections,
            static fn (Detection $detection): bool => $detection->kind() === Detection::KIND_N_PLUS_ONE,
        ));
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    private function writeBaseline(string $path, array $hotspots): int
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            $this->line(sprintf('Error: baseline directory "%s" could not be created.', $directory));

            return self::EXIT_USAGE;
        }

        if (@file_put_contents($path, Baseline::render($hotspots)) === false) {
            $this->line(sprintf('Error: baseline "%s" could not be written.', $path));

            return self::EXIT_USAGE;
        }

        $this->line(sprintf(
            'Baseline written to %s with %d hotspot%s.',
            $path,
            count($hotspots),
            count($hotspots) === 1 ? '' : 's',
        ));

        return self::EXIT_OK;
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    private function writeMerged(string $path, int $threshold, array $hotspots, Statistics $statistics): bool
    {
        try {
            (new JsonReporter(new Options(threshold: $threshold, basePath: '')))->write($path, $hotspots, $statistics);
        } catch (Throwable $exception) {
            $this->line('Error: ' . $exception->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param list<Hotspot> $top
     * @param list<Violation> $violations
     */
    private function renderText(
        LoadedReports $reports,
        Statistics $statistics,
        array $top,
        array $violations,
        Budget $budget,
        bool $colors,
        int $threshold,
        int $newOrWorse,
    ): void {
        $this->line(sprintf(
            'Analysed %d report file%s: %s',
            count($reports->files),
            count($reports->files) === 1 ? '' : 's',
            implode(', ', $reports->files),
        ));

        if (count($reports->files) > 1 && $reports->spreadInSeconds() > self::STALE_SPREAD_SECONDS) {
            $this->line(sprintf(
                'Warning: these reports were written %d minutes apart — is the directory left over from an earlier run?',
                intdiv($reports->spreadInSeconds(), 60),
            ));
        }

        $this->out((new ConsoleReporter(new Options(
            threshold: $threshold,
            basePath: '',
            colors: $colors,
        )))->render($top, $statistics));

        if ($budget->baseline !== null) {
            $this->line(sprintf(
                '  Baseline: %d known hotspot%s, %d new or worse.',
                $budget->baseline->count(),
                $budget->baseline->count() === 1 ? '' : 's',
                $newOrWorse,
            ));
        }

        if (!$budget->isActive()) {
            $this->line('  No budget set, so nothing can fail: add --max-queries, --max-hotspots, --fail-on-any or --baseline.');

            return;
        }

        if ($violations === []) {
            $this->line('  Within budget.');

            return;
        }

        $this->line(sprintf('  %d violation%s:', count($violations), count($violations) === 1 ? '' : 's'));

        foreach ($violations as $violation) {
            $this->line(sprintf('    - [%s] %s', $violation->rule, $violation->message));
        }
    }

    /**
     * @param list<Hotspot> $hotspots
     * @param list<Violation> $violations
     */
    private function renderJson(
        LoadedReports $reports,
        Statistics $statistics,
        array $hotspots,
        array $violations,
        Budget $budget,
    ): void {
        $this->out((string) json_encode([
            'status' => $violations === [] ? 'ok' : 'violations',
            'budget_active' => $budget->isActive(),
            'files' => $reports->files,
            'summary' => [
                'analysed_tests' => $statistics->analysedTests,
                'recorded_queries' => $statistics->recordedQueries,
                'affected_tests' => $statistics->affectedTests,
                'hotspots' => $statistics->hotspots,
                'worst_queries_in_one_test' => $hotspots === [] ? 0 : $hotspots[0]->maxCount(),
            ],
            'violations' => array_map(static fn (Violation $violation): array => [
                'rule' => $violation->rule,
                'message' => $violation->message,
                'origin' => $violation->hotspot?->origin->relative(''),
            ], $violations),
            'hotspots' => array_map(static fn (Hotspot $hotspot): array => $hotspot->toArray(''), $hotspots),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    /**
     * @param list<Hotspot> $top
     * @param list<Violation> $violations
     */
    private function renderGithub(
        LoadedReports $reports,
        Statistics $statistics,
        array $top,
        array $violations,
        Budget $budget,
        bool $colors,
        int $threshold,
        int $newOrWorse,
    ): void {
        foreach ($violations as $violation) {
            $this->line($this->annotation('error', 'N+1 query budget', $violation->message, $violation->hotspot));
        }

        if ($violations === []) {
            foreach ($top as $hotspot) {
                $this->line($this->annotation(
                    'warning',
                    $hotspot->kind() . ' queries',
                    sprintf(
                        '%dx in one test, %d overall: %s',
                        $hotspot->maxCount(),
                        $hotspot->totalCount(),
                        trim((string) preg_replace('/\s+/', ' ', $hotspot->sql)),
                    ),
                    $hotspot,
                ));
            }
        }

        $this->renderText($reports, $statistics, $top, $violations, $budget, $colors, $threshold, $newOrWorse);

        $this->appendStepSummary($top, $violations, $statistics);
        $this->appendOutputs($statistics, $top, $violations);
    }

    private function annotation(string $level, string $title, string $message, ?Hotspot $hotspot): string
    {
        $properties = ['title=' . $this->escapeProperty($title)];

        if ($hotspot !== null && !$hotspot->origin->isUnknown()) {
            array_unshift(
                $properties,
                'file=' . $this->escapeProperty($hotspot->origin->file),
                'line=' . $hotspot->origin->line,
            );
        }

        return sprintf('::%s %s::%s', $level, implode(',', $properties), $this->escapeData($message));
    }

    /**
     * @param list<Hotspot> $top
     * @param list<Violation> $violations
     */
    private function appendStepSummary(array $top, array $violations, Statistics $statistics): void
    {
        $path = $this->env['GITHUB_STEP_SUMMARY'] ?? '';

        if ($path === '') {
            return;
        }

        $lines = ['### N+1 query report', ''];
        $lines[] = sprintf(
            '%d hotspot%s in %d analysed test%s (%d queries recorded).',
            $statistics->hotspots,
            $statistics->hotspots === 1 ? '' : 's',
            $statistics->analysedTests,
            $statistics->analysedTests === 1 ? '' : 's',
            $statistics->recordedQueries,
        );
        $lines[] = '';

        if ($top !== []) {
            $lines[] = '| Worst test | Kind | Where | Query |';
            $lines[] = '| --- | --- | --- | --- |';

            foreach ($top as $hotspot) {
                $lines[] = sprintf(
                    '| %dx | %s | `%s` | `%s` |',
                    $hotspot->maxCount(),
                    $hotspot->kind(),
                    $hotspot->origin->relative(''),
                    str_replace('|', '\\|', trim((string) preg_replace('/\s+/', ' ', $hotspot->sql))),
                );
            }

            $lines[] = '';
        }

        foreach ($violations as $violation) {
            $lines[] = sprintf('- **%s**: %s', $violation->rule, $violation->message);
        }

        @file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND);
    }

    /**
     * @param list<Hotspot> $top
     * @param list<Violation> $violations
     */
    private function appendOutputs(Statistics $statistics, array $top, array $violations): void
    {
        $path = $this->env['GITHUB_OUTPUT'] ?? '';

        if ($path === '') {
            return;
        }

        $outputs = [
            'status' => $violations === [] ? 'ok' : 'violations',
            'hotspots' => (string) $statistics->hotspots,
            'violations' => (string) count($violations),
            'worst_queries' => (string) ($top === [] ? 0 : $top[0]->maxCount()),
        ];

        $lines = [];

        foreach ($outputs as $name => $value) {
            $lines[] = $name . '=' . $value;
        }

        @file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{paths: list<string>, maxQueries: ?int, maxHotspots: ?int, failOnAny: bool, baseline: ?string, writeBaseline: ?string, top: int, format: string, output: ?string, kind: string, allowEmpty: bool, colors: ?bool, help: bool}
     *
     * @throws LoadFailure on bad usage
     */
    private function parse(array $arguments): array
    {
        $parsed = [
            'paths' => [],
            'maxQueries' => null,
            'maxHotspots' => null,
            'failOnAny' => false,
            'baseline' => null,
            'writeBaseline' => null,
            'top' => 10,
            'format' => 'text',
            'output' => null,
            'kind' => 'all',
            'allowEmpty' => false,
            'colors' => null,
            'help' => false,
        ];

        for ($index = 0; $index < count($arguments); ++$index) {
            $argument = $arguments[$index];

            if ($argument === 'analyze' && $index === 0) {
                continue;
            }

            if ($argument === '' || !str_starts_with($argument, '-')) {
                $parsed['paths'][] = $argument;

                continue;
            }

            $name = $argument;
            $value = null;

            if (str_contains($argument, '=')) {
                [$name, $value] = explode('=', $argument, 2);
            }

            if (in_array($name, self::WITH_VALUE, true) && $value === null) {
                $value = $arguments[++$index] ?? null;

                if ($value === null) {
                    throw new LoadFailure(sprintf('Option "%s" needs a value.', $name));
                }
            }

            switch ($name) {
                case '-h':
                case '--help':
                    $parsed['help'] = true;
                    break;
                case '--max-queries':
                    $parsed['maxQueries'] = $this->toInt($name, (string) $value);
                    break;
                case '--max-hotspots':
                    $parsed['maxHotspots'] = $this->toInt($name, (string) $value);
                    break;
                case '--top':
                    $parsed['top'] = max(1, $this->toInt($name, (string) $value));
                    break;
                case '--baseline':
                    $parsed['baseline'] = (string) $value;
                    break;
                case '--write-baseline':
                    $parsed['writeBaseline'] = (string) $value;
                    break;
                case '--output':
                    $parsed['output'] = (string) $value;
                    break;
                case '--fail-on-any':
                    $parsed['failOnAny'] = true;
                    break;
                case '--allow-empty':
                    $parsed['allowEmpty'] = true;
                    break;
                case '--no-color':
                    $parsed['colors'] = false;
                    break;
                case '--color':
                    $parsed['colors'] = true;
                    break;
                case '--kind':
                    $parsed['kind'] = match (strtolower((string) $value)) {
                        'all' => 'all',
                        'n+1', 'nplusone', 'n-plus-one' => Detection::KIND_N_PLUS_ONE,
                        default => throw new LoadFailure(sprintf('Unknown --kind "%s"; use "all" or "n+1".', $value)),
                    };
                    break;
                case '--format':
                    $format = strtolower((string) $value);

                    if (!in_array($format, ['text', 'json', 'github'], true)) {
                        throw new LoadFailure(sprintf('Unknown --format "%s"; use text, json or github.', $value));
                    }

                    $parsed['format'] = $format;
                    break;
                default:
                    throw new LoadFailure(sprintf('Unknown option "%s".', $name));
            }
        }

        if ($parsed['paths'] === []) {
            $parsed['paths'] = [self::DEFAULT_PATH];
        }

        return $parsed;
    }

    private function toInt(string $option, string $value): int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new LoadFailure(sprintf('Option "%s" needs a whole number, got "%s".', $option, $value));
        }

        return (int) $value;
    }

    private function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private function escapeProperty(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }

    private function supportsColors(): bool
    {
        if (($this->env['NO_COLOR'] ?? '') !== '') {
            return false;
        }

        return defined('STDOUT') && function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }

    private function line(string $text): void
    {
        $this->out($text . PHP_EOL);
    }

    private function out(string $text): void
    {
        ($this->out)($text);
    }

    public static function usage(): string
    {
        return <<<'TXT'
            n-plus-one — merge the N+1 reports of a test run and check them against a budget.

            Usage:
              n-plus-one [analyze] [<path>...] [options]

            <path> may be a report file, a directory of reports (one per process under a
            parallel runner) or a glob. Defaults to "build/n-plus-one".

            Budget — any of these lets the command fail; without them it only reports:
              --max-queries=N        fail when one test fires a shape more than N times
              --max-hotspots=N       fail when the run has more than N hotspots
              --fail-on-any          fail when any hotspot is left after the baseline
              --baseline=FILE        accept the hotspots in FILE; fail on new or worse ones

            Other options:
              --write-baseline=FILE  record the current hotspots as the accepted baseline
              --output=FILE          write the merged report as JSON
              --kind=all|n+1         analyse every repeat, or only varying-binding N+1
              --top=N                how many hotspots to show (default 10)
              --format=text|json|github
              --allow-empty          exit 0 instead of 2 when there is nothing to analyse
              --no-color, --color
              -h, --help

            Exit codes: 0 within budget, 1 budget exceeded, 2 nothing to analyse or bad usage.
            TXT;
    }
}
