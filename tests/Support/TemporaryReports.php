<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Support;

use NPlusOne\PHPUnit\Analysis\Detection;

/**
 * A throwaway directory of JSON reports, shaped exactly like the ones the
 * extension writes. Call the two set-up methods from the test case's own
 * setUp()/tearDown().
 */
trait TemporaryReports
{
    private string $directory = '';

    protected function setUpTemporaryReports(): void
    {
        $this->directory = sys_get_temp_dir() . '/n-plus-one-tests-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDownTemporaryReports(): void
    {
        if ($this->directory === '' || !is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->directory);
        $this->directory = '';
    }

    protected function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    /**
     * @param array<string, mixed> $report
     */
    protected function writeReport(string $name, array $report): string
    {
        $report += ['generated_at' => date(DATE_ATOM), 'threshold' => 3];
        $report['summary'] ??= [
            'analysed_tests' => 1,
            'recorded_queries' => 1,
            'affected_tests' => 1,
            'hotspots' => count($report['hotspots'] ?? []),
        ];

        $path = $this->path($name);
        file_put_contents($path, (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @param array<string, int> $tests
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function hotspot(array $tests, array $overrides = []): array
    {
        $total = array_sum($tests);

        return $overrides + [
            'kind' => Detection::KIND_N_PLUS_ONE,
            'sql' => 'select * from "users" where "id" = ?',
            'normalized_sql' => 'select * from "users" where "id" = ?',
            'origin' => 'app/Http/Controllers/OrderController.php:31',
            'function' => 'OrderController->index()',
            'connection' => 'testing',
            'max_queries_in_one_test' => $tests === [] ? 0 : max($tests),
            'total_queries' => $total,
            'total_time_ms' => $total * 0.5,
            'tests' => $tests,
        ];
    }
}
