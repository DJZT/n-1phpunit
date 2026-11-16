<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Console;

use JsonException;
use NPlusOne\PHPUnit\Analysis\Hotspot;

/**
 * A snapshot of the N+1 places a project already has, so CI can fail on new
 * and worsening ones while the known backlog is paid off separately.
 */
final class Baseline
{
    /**
     * @param array<string, int> $entries key => the worst single-test count that is accepted
     */
    private function __construct(private readonly array $entries)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @throws LoadFailure
     */
    public static function load(string $path): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new LoadFailure(sprintf('Baseline "%s" could not be read.', $path));
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LoadFailure(sprintf('Baseline "%s" is not valid JSON: %s', $path, $exception->getMessage()));
        }

        if (!is_array($decoded) || !is_array($decoded['entries'] ?? null)) {
            throw new LoadFailure(sprintf('Baseline "%s" has no "entries" list.', $path));
        }

        $entries = [];

        foreach ($decoded['entries'] as $entry) {
            if (!is_array($entry) || !is_string($entry['origin'] ?? null) || !is_string($entry['normalized_sql'] ?? null)) {
                throw new LoadFailure(sprintf('Baseline "%s" has a malformed entry.', $path));
            }

            $key = self::keyOf($entry['origin'], $entry['normalized_sql']);
            $entries[$key] = max($entries[$key] ?? 0, (int) ($entry['max_queries_in_one_test'] ?? 0));
        }

        return new self($entries);
    }

    public static function key(Hotspot $hotspot): string
    {
        return self::keyOf($hotspot->origin->relative(''), $hotspot->normalizedSql);
    }

    public function knows(Hotspot $hotspot): bool
    {
        return isset($this->entries[self::key($hotspot)]);
    }

    public function limitFor(Hotspot $hotspot): ?int
    {
        return $this->entries[self::key($hotspot)] ?? null;
    }

    /**
     * A hotspot is accepted while it is in the baseline and no worse than it
     * was when the baseline was taken.
     */
    public function allows(Hotspot $hotspot): bool
    {
        $limit = $this->limitFor($hotspot);

        return $limit !== null && $hotspot->maxCount() <= $limit;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    public static function render(array $hotspots): string
    {
        $entries = array_map(static fn (Hotspot $hotspot): array => [
            'origin' => $hotspot->origin->relative(''),
            'normalized_sql' => $hotspot->normalizedSql,
            'kind' => $hotspot->kind(),
            'max_queries_in_one_test' => $hotspot->maxCount(),
        ], $hotspots);

        usort($entries, static fn (array $a, array $b): int => [$a['origin'], $a['normalized_sql']] <=> [$b['origin'], $b['normalized_sql']]);

        return (string) json_encode(
            ['generated_at' => date(DATE_ATOM), 'entries' => $entries],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . PHP_EOL;
    }

    private static function keyOf(string $origin, string $normalizedSql): string
    {
        return $origin . '|' . $normalizedSql;
    }
}
