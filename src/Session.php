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
use NPlusOne\PHPUnit\Report\JsonReporter;
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

    /** @var callable(string): void */
    private $writer;

    public function __construct(
        private readonly Options $options,
        private readonly QueryRecorder $recorder = new QueryRecorder(),
        ?LaravelQueryBinder $binder = null,
        private readonly Aggregator $aggregator = new Aggregator(),
        ?Detector $detector = null,
        ?callable $writer = null,
    ) {
        $this->binder = $binder ?? new LaravelQueryBinder($this->recorder);
        $this->detector = $detector ?? new Detector($this->options);
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

        $hotspots = $this->aggregator->top($this->options->limit);
        $statistics = new Statistics(
            analysedTests: $this->analysedTests,
            recordedQueries: $this->recorder->totalQueries(),
            affectedTests: $this->aggregator->affectedTestCount(),
            hotspots: $this->aggregator->count(),
            listenerAttached: $this->binder->isAttached(),
        );

        ($this->writer)((new ConsoleReporter($this->options))->render($hotspots, $statistics));

        if ($this->options->reportFile === null) {
            return;
        }

        try {
            (new JsonReporter($this->options))->write($this->options->reportFile, $hotspots, $statistics);
        } catch (Throwable $exception) {
            ($this->writer)('  N+1 report could not be written: ' . $exception->getMessage() . PHP_EOL);
        }
    }
}
