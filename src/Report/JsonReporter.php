<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Report;

use NPlusOne\PHPUnit\Analysis\Hotspot;
use NPlusOne\PHPUnit\Configuration\Options;
use RuntimeException;

/**
 * Machine readable version of the report, for CI artefacts or diffing between runs.
 */
final class JsonReporter
{
    public function __construct(private readonly Options $options)
    {
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    public function toJson(array $hotspots, Statistics $statistics): string
    {
        $payload = [
            'generated_at' => date(DATE_ATOM),
            'threshold' => $this->options->threshold,
            'summary' => [
                'analysed_tests' => $statistics->analysedTests,
                'recorded_queries' => $statistics->recordedQueries,
                'affected_tests' => $statistics->affectedTests,
                'hotspots' => $statistics->hotspots,
            ],
            'hotspots' => array_map(
                fn (Hotspot $hotspot): array => $hotspot->toArray($this->options->basePath),
                $hotspots,
            ),
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    public function write(string $path, array $hotspots, Statistics $statistics): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create report directory "%s".', $directory));
        }

        if (@file_put_contents($path, $this->toJson($hotspots, $statistics) . PHP_EOL) === false) {
            throw new RuntimeException(sprintf('Unable to write the N+1 report to "%s".', $path));
        }
    }
}
