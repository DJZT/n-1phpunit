<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Report;

use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Hotspot;
use NPlusOne\PHPUnit\Configuration\Options;
use RuntimeException;

/**
 * The Code Climate subset GitLab reads as a Code Quality report:
 *
 *     artifacts:
 *       reports:
 *         codequality: build/n-plus-one-codequality.json
 *
 * GitLab then shows each hotspot in the merge request widget and, when the
 * line is part of the diff, inline in the Changes tab.
 */
final class CodeQualityReporter
{
    public const CHECK_N_PLUS_ONE = 'n-plus-one';
    public const CHECK_DUPLICATE = 'duplicate-query';

    /**
     * GitLab accepts info, minor, major, critical and blocker. The worst
     * single test decides: that is what one request costs a user.
     */
    private const N_PLUS_ONE_SEVERITY = [
        100 => 'blocker',
        50 => 'critical',
        20 => 'major',
        0 => 'minor',
    ];

    /** A repeated identical query wastes less than a growing one, so one notch down. */
    private const DUPLICATE_SEVERITY = [
        100 => 'critical',
        50 => 'major',
        20 => 'minor',
        0 => 'info',
    ];

    public function __construct(private readonly Options $options)
    {
    }

    /**
     * @param list<Hotspot> $hotspots
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(array $hotspots): array
    {
        $issues = [];

        foreach ($hotspots as $hotspot) {
            // Without a file and a line GitLab has nothing to attach the
            // finding to, so such a hotspot is left to the other reports.
            if ($hotspot->origin->isUnknown()) {
                continue;
            }

            $issues[] = [
                'type' => 'issue',
                'check_name' => $hotspot->kind() === Detection::KIND_N_PLUS_ONE
                    ? self::CHECK_N_PLUS_ONE
                    : self::CHECK_DUPLICATE,
                'description' => $this->description($hotspot),
                'categories' => ['Performance'],
                'severity' => $this->severity($hotspot),
                'fingerprint' => $this->fingerprint($hotspot),
                'location' => [
                    'path' => $hotspot->origin->relativeFile($this->options->basePath),
                    'lines' => [
                        'begin' => $hotspot->origin->line,
                        'end' => $hotspot->origin->line,
                    ],
                ],
            ];
        }

        return $issues;
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    public function render(array $hotspots): string
    {
        return (string) json_encode(
            $this->toArray($hotspots),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . PHP_EOL;
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    public function write(string $path, array $hotspots): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the report directory "%s".', $directory));
        }

        if (@file_put_contents($path, $this->render($hotspots)) === false) {
            throw new RuntimeException(sprintf('Unable to write the Code Quality report to "%s".', $path));
        }
    }

    private function description(Hotspot $hotspot): string
    {
        $tests = $hotspot->tests();
        $worst = (string) array_key_first($tests);

        $description = sprintf(
            '%s: %d queries of one shape in a single test (%s)',
            $hotspot->kind() === Detection::KIND_N_PLUS_ONE ? 'N+1' : 'Duplicate query',
            $hotspot->maxCount(),
            $worst,
        );

        if (count($tests) > 1) {
            $description .= sprintf(', %d across %d tests', $hotspot->totalCount(), count($tests));
        }

        if ($hotspot->origin->via !== null) {
            $description .= sprintf(', issued by %s', $hotspot->origin->via);
        }

        return $description . '. ' . $this->sql($hotspot->sql);
    }

    private function sql(string $sql): string
    {
        $sql = trim((string) preg_replace('/\s+/', ' ', $sql));

        return mb_strlen($sql) <= $this->options->sqlMaxLength
            ? $sql
            : mb_substr($sql, 0, $this->options->sqlMaxLength - 1) . '…';
    }

    private function severity(Hotspot $hotspot): string
    {
        $scale = $hotspot->kind() === Detection::KIND_N_PLUS_ONE
            ? self::N_PLUS_ONE_SEVERITY
            : self::DUPLICATE_SEVERITY;

        foreach ($scale as $from => $severity) {
            if ($hotspot->maxCount() >= $from) {
                return $severity;
            }
        }

        return 'info';
    }

    /**
     * Identity of the problem, not of its size: GitLab compares fingerprints
     * with the target branch's report to tell new findings from known ones, so
     * a hotspot that merely got worse must keep the same one. The line number
     * is left out on purpose — code moves, the problem does not.
     */
    private function fingerprint(Hotspot $hotspot): string
    {
        return md5(implode('|', [
            $hotspot->kind(),
            $hotspot->origin->relativeFile($this->options->basePath),
            (string) $hotspot->origin->function,
            $hotspot->normalizedSql,
        ]));
    }
}
