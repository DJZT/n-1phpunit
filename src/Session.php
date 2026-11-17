<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit;

use NPlusOne\PHPUnit\Analysis\Aggregator;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Detector;
use NPlusOne\PHPUnit\Configuration\Options;
use NPlusOne\PHPUnit\Laravel\LaravelQueryBinder;
use NPlusOne\PHPUnit\Recording\QueryRecorder;
use NPlusOne\PHPUnit\Report\ConsoleReporter;
use NPlusOne\PHPUnit\Report\HtmlReporter;
use NPlusOne\PHPUnit\Report\JsonReporter;
use NPlusOne\PHPUnit\Report\ReportDestination;
use NPlusOne\PHPUnit\Report\Statistics;
use Throwable;

/**
 * Ties everything together and owns the state of a single PHPUnit run.
 */
final class Session
{
    private int $analysedTests = 0;

    private readonly LaravelQueryBinder $binder;

    private readonly Detector $detector;

    private readonly ReportDestination $destination;

    /** @var callable(string): void */
    private $writer;

    public function __construct(
        private readonly Options $options,
        private readonly QueryRecorder $recorder = new QueryRecorder(),
        ?LaravelQueryBinder $binder = null,
        private readonly Aggregator $aggregator = new Aggregator(),
        ?Detector $detector = null,
        ?callable $writer = null,
        ?ReportDestination $destination = null,
    ) {
        $this->binder = $binder ?? new LaravelQueryBinder($this->recorder);
        $this->detector = $detector ?? new Detector($this->options);
        $this->destination = $destination ?? ReportDestination::fromOptions($this->options);
        $this->writer = $writer ?? static function (string $output): void {
            if (defined('STDOUT')) {
                fwrite(STDOUT, $output);

                return;
            }

            print $output;
        };
    }

    public function options(): Options
    {
        return $this->options;
    }

    public function recorder(): QueryRecorder
    {
        return $this->recorder;
    }

    public function binder(): LaravelQueryBinder
    {
        return $this->binder;
    }

    public function startTest(string $testId): void
    {
        if (!$this->options->appliesTo($testId)) {
            return;
        }

        ++$this->analysedTests;
        $this->recorder->start($testId);
        $this->binder->attach();
    }

    public function endTest(): void
    {
        if (!$this->recorder->isRecording()) {
            return;
        }

        $testId = (string) $this->recorder->currentTestId();
        $this->aggregator->addAll($this->detector->detect($testId, $this->recorder->stop()));
    }

    /**
     * Detections for the test that is running right now, so a test can assert
     * on its own queries (see AssertsNoNPlusOneQueries).
     *
     * @return list<Detection>
     */
    public function currentDetections(): array
    {
        if (!$this->recorder->isRecording()) {
            return [];
        }

        return $this->detector->detect((string) $this->recorder->currentTestId(), $this->recorder->queries());
    }

    public function finish(): void
    {
        $this->endTest();

        $statistics = new Statistics(
            analysedTests: $this->analysedTests,
            recordedQueries: $this->recorder->totalQueries(),
            affectedTests: $this->aggregator->affectedTestCount(),
            hotspots: $this->aggregator->count(),
            listenerAttached: $this->binder->isAttached(),
        );

        if ($this->options->console) {
            ($this->writer)((new ConsoleReporter($this->options))->render(
                $this->aggregator->top($this->options->limit),
                $statistics,
            ));
        }

        // `limit` trims what a human reads, never what is written to a file:
        // under a parallel runner every worker's report is merged later, and a
        // hotspot dropped here would be lost for good.
        $all = $this->aggregator->top(PHP_INT_MAX);

        $this->writeReport(
            $this->destination->path(),
            fn (string $path) => (new JsonReporter($this->options))->write($path, $all, $statistics),
        );

        $this->writeReport(
            $this->destination->htmlPath(),
            fn (string $path) => (new HtmlReporter($this->options))->write($path, $all, $statistics),
        );
    }

    /**
     * @param callable(string): void $write
     */
    private function writeReport(?string $path, callable $write): void
    {
        if ($path === null) {
            return;
        }

        try {
            $write($path);
        } catch (Throwable $exception) {
            ($this->writer)('  N+1 report could not be written: ' . $exception->getMessage() . PHP_EOL);
        }
    }
}
