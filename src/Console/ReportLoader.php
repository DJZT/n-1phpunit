<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Console;

use JsonException;
use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Recording\Origin;

/**
 * Reads the JSON reports a run produced — one per process under a parallel
 * runner — and turns them back into detections so the ordinary Aggregator can
 * merge and rank them.
 */
final class ReportLoader
{
    /**
     * @param list<string> $paths files, directories or globs
     *
     * @throws LoadFailure
     */
    public function load(array $paths): LoadedReports
    {
        $files = $this->expand($paths);

        $detections = [];
        $analysedTests = 0;
        $recordedQueries = 0;
        $affectedTests = 0;
        $threshold = 0;
        $written = [];

        foreach ($files as $file) {
            $report = $this->decode($file);

            $generatedAt = is_string($report['generated_at'] ?? null) ? strtotime($report['generated_at']) : false;

            if ($generatedAt !== false) {
                $written[] = $generatedAt;
            }

            $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
            $analysedTests += (int) ($summary['analysed_tests'] ?? 0);
            $recordedQueries += (int) ($summary['recorded_queries'] ?? 0);
            $affectedTests += (int) ($summary['affected_tests'] ?? 0);
            $threshold = max($threshold, (int) ($report['threshold'] ?? 0));

            foreach ($this->hotspotRows($file, $report) as $row) {
                foreach ($this->detectionsOf($row) as $detection) {
                    $detections[] = $detection;
                }
            }
        }

        return new LoadedReports(
            files: $files,
            detections: $detections,
            analysedTests: $analysedTests,
            recordedQueries: $recordedQueries,
            affectedTests: $affectedTests,
            threshold: $threshold > 0 ? $threshold : 3,
            oldest: $written === [] ? 0 : min($written),
            newest: $written === [] ? 0 : max($written),
        );
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     *
     * @throws LoadFailure
     */
    private function expand(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                $files = array_merge($files, $this->jsonIn($path));

                continue;
            }

            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            $matched = glob($path) ?: [];

            if ($matched === []) {
                throw new LoadFailure(sprintf('No report found at "%s".', $path));
            }

            foreach ($matched as $match) {
                $files = array_merge($files, is_dir($match) ? $this->jsonIn($match) : [$match]);
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function jsonIn(string $directory): array
    {
        $found = glob(rtrim($directory, '/') . '/*.json') ?: [];
        sort($found);

        return array_values($found);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LoadFailure
     */
    private function decode(string $file): array
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw new LoadFailure(sprintf('Report "%s" could not be read.', $file));
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LoadFailure(sprintf('Report "%s" is not valid JSON: %s', $file, $exception->getMessage()));
        }

        if (!is_array($decoded)) {
            throw new LoadFailure(sprintf('Report "%s" does not contain a JSON object.', $file));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<array<string, mixed>>
     *
     * @throws LoadFailure
     */
    private function hotspotRows(string $file, array $report): array
    {
        $rows = $report['hotspots'] ?? [];

        if (!is_array($rows)) {
            throw new LoadFailure(sprintf('Report "%s" has a malformed "hotspots" section.', $file));
        }

        $valid = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new LoadFailure(sprintf('Report "%s" has a malformed hotspot entry.', $file));
            }

            $valid[] = $row;
        }

        return $valid;
    }

    /**
     * One detection per test that hit the hotspot, which is exactly what the
     * aggregator consumed when the report was written.
     *
     * @param array<string, mixed> $row
     *
     * @return list<Detection>
     */
    private function detectionsOf(array $row): array
    {
        $sql = is_string($row['sql'] ?? null) ? $row['sql'] : '';
        $normalized = is_string($row['normalized_sql'] ?? null) ? $row['normalized_sql'] : $sql;
        $origin = $this->origin($row);
        $connection = is_string($row['connection'] ?? null) ? $row['connection'] : null;
        $isNPlusOne = ($row['kind'] ?? Detection::KIND_N_PLUS_ONE) === Detection::KIND_N_PLUS_ONE;
        $totalTime = (float) ($row['total_time_ms'] ?? 0);

        $tests = is_array($row['tests'] ?? null) ? $row['tests'] : [];
        $tests = array_filter($tests, static fn (mixed $count): bool => is_int($count) || is_float($count) || is_numeric($count));

        if ($tests === []) {
            $tests = ['unknown test' => (int) ($row['max_queries_in_one_test'] ?? $row['total_queries'] ?? 0)];
        }

        $totalCount = max(1, (int) array_sum(array_map('intval', $tests)));
        $detections = [];

        foreach ($tests as $testId => $count) {
            $count = (int) $count;

            if ($count < 1) {
                continue;
            }

            $detections[] = new Detection(
                testId: (string) $testId,
                sql: $sql,
                normalizedSql: $normalized,
                origin: $origin,
                count: $count,
                // Only the kind survives the round trip, so rebuild the
                // smallest binding count that reproduces it.
                distinctBindings: $isNPlusOne ? max(2, $count) : 1,
                totalTimeMs: $totalTime * ($count / $totalCount),
                connection: $connection,
            );
        }

        return $detections;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function origin(array $row): Origin
    {
        $location = is_string($row['origin'] ?? null) ? $row['origin'] : '';
        $function = is_string($row['function'] ?? null) ? $row['function'] : null;

        if ($location === '' || $location === 'unknown location') {
            return Origin::unknown();
        }

        if (preg_match('/^(?<file>.+):(?<line>\d+)$/', $location, $matches) === 1) {
            return new Origin($matches['file'], (int) $matches['line'], $function);
        }

        return new Origin($location, 0, $function);
    }
}
