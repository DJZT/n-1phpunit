<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Report;

use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Hotspot;
use NPlusOne\PHPUnit\Configuration\Options;

/**
 * Renders the "top N" table printed once the whole test run is over.
 */
final class ConsoleReporter
{
    private const WIDTH = 78;

    public function __construct(private readonly Options $options)
    {
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    public function render(array $hotspots, Statistics $statistics): string
    {
        if ($statistics->analysedTests === 0) {
            return '';
        }

        $lines = [
            '',
            $this->dim(str_repeat('=', self::WIDTH)),
            $this->bold('  N+1 query report'),
            $this->dim(str_repeat('=', self::WIDTH)),
        ];

        if ($hotspots === []) {
            $lines[] = '  ' . $this->green('No repeated query patterns found.');
            $lines[] = '  ' . $this->summary($statistics);

            if (!$statistics->listenerAttached) {
                $lines[] = '  ' . $this->yellow(
                    'No query listener could be attached: the tests never booted a Laravel'
                    . PHP_EOL . '  application with a "db" binding. See NPlusOne::listen($this->app).',
                );
            }
            $lines[] = '';

            return implode(PHP_EOL, $lines) . PHP_EOL;
        }

        $shown = count($hotspots);
        $lines[] = sprintf(
            '  Top %d of %d hotspot%s (a query shape repeated %d+ times in one test)',
            $shown,
            $statistics->hotspots,
            $statistics->hotspots === 1 ? '' : 's',
            $this->options->threshold,
        );
        $lines[] = '';

        $rank = 0;
        foreach ($hotspots as $hotspot) {
            ++$rank;
            $lines = array_merge($lines, $this->renderHotspot($rank, $hotspot));
        }

        $lines[] = $this->dim(str_repeat('-', self::WIDTH));
        $lines[] = '  ' . $this->summary($statistics);
        $lines[] = '';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @return list<string>
     */
    private function renderHotspot(int $rank, Hotspot $hotspot): array
    {
        $isNPlusOne = $hotspot->kind() === Detection::KIND_N_PLUS_ONE;
        // Pad before colouring: ANSI escapes would otherwise count towards the width.
        $badge = str_pad($isNPlusOne ? Detection::KIND_N_PLUS_ONE : Detection::KIND_DUPLICATE, 10);
        $badge = $isNPlusOne ? $this->red($badge) : $this->yellow($badge);

        $lines = [];
        $lines[] = sprintf(
            ' %s %s  %s  %s',
            $this->bold(str_pad('#' . $rank, 4)),
            $this->bold(str_pad($hotspot->maxCount() . 'x', 6)),
            $badge,
            $this->cyan($hotspot->origin->describe($this->options->basePath)),
        );
        $lines[] = '        ' . $this->truncate($this->squash($hotspot->sql));

        $tests = $hotspot->tests();
        $first = array_key_first($tests);
        $worst = sprintf('%s (%dx)', (string) $first, $tests[$first]);
        if (count($tests) > 1) {
            $worst .= sprintf(' +%d more test%s', count($tests) - 1, count($tests) === 2 ? '' : 's');
        }
        $lines[] = '        ' . $this->dim('in ' . $worst);

        $lines[] = '        ' . $this->dim(sprintf(
            '%d quer%s overall, %.2f ms',
            $hotspot->totalCount(),
            $hotspot->totalCount() === 1 ? 'y' : 'ies',
            $hotspot->totalTimeMs(),
        ));
        $lines[] = '';

        return $lines;
    }

    private function summary(Statistics $statistics): string
    {
        return $this->dim(sprintf(
            '%d quer%s recorded in %d test%s; %d test%s affected.',
            $statistics->recordedQueries,
            $statistics->recordedQueries === 1 ? 'y' : 'ies',
            $statistics->analysedTests,
            $statistics->analysedTests === 1 ? '' : 's',
            $statistics->affectedTests,
            $statistics->affectedTests === 1 ? '' : 's',
        ));
    }

    private function squash(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    private function truncate(string $value): string
    {
        if (mb_strlen($value) <= $this->options->sqlMaxLength) {
            return $value;
        }

        return mb_substr($value, 0, $this->options->sqlMaxLength - 1) . '…';
    }

    private function color(string $value, string $code): string
    {
        return $this->options->colors ? "\033[" . $code . 'm' . $value . "\033[0m" : $value;
    }

    private function bold(string $value): string
    {
        return $this->color($value, '1');
    }

    private function dim(string $value): string
    {
        return $this->color($value, '2');
    }

    private function red(string $value): string
    {
        return $this->color($value, '31;1');
    }

    private function green(string $value): string
    {
        return $this->color($value, '32');
    }

    private function yellow(string $value): string
    {
        return $this->color($value, '33');
    }

    private function cyan(string $value): string
    {
        return $this->color($value, '36');
    }
}
