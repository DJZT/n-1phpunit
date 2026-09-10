<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Recording;

/**
 * Walks a backtrace and returns the innermost frame that belongs to the
 * application under test, i.e. the loop that actually fires the queries.
 */
final class BacktraceOriginResolver
{
    private const DEPTH = 60;

    /** @var list<string> */
    private array $ignoredFragments;

    /**
     * @param list<string> $extraIgnoredFragments
     */
    public function __construct(array $extraIgnoredFragments = [])
    {
        $this->ignoredFragments = array_merge(
            [
                DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
                dirname(__DIR__) . DIRECTORY_SEPARATOR, // this package, when used from a path repository
            ],
            $extraIgnoredFragments,
        );
    }

    public function capture(): Origin
    {
        return $this->resolve(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::DEPTH));
    }

    /**
     * @param list<array<string, mixed>> $frames
     */
    public function resolve(array $frames): Origin
    {
        $firstWithFile = null;

        foreach ($frames as $index => $frame) {
            $file = $frame['file'] ?? null;
            if (!is_string($file) || $file === '') {
                continue;
            }

            $origin = new Origin($file, (int) ($frame['line'] ?? 0), $this->enclosingFunction($frames, $index));
            $firstWithFile ??= $origin;

            if (!$this->isIgnored($file)) {
                return $origin;
            }
        }

        return $firstWithFile ?? Origin::unknown();
    }

    /**
     * A backtrace frame stores the location a call was made *from*, so the
     * function that contains it is described by the next frame.
     *
     * @param list<array<string, mixed>> $frames
     */
    private function enclosingFunction(array $frames, int $index): ?string
    {
        $frame = $frames[$index + 1] ?? $frames[$index] ?? null;
        if ($frame === null || !isset($frame['function'])) {
            return null;
        }

        $class = isset($frame['class']) ? $this->shortName((string) $frame['class']) . (string) ($frame['type'] ?? '::') : '';

        return $class . (string) $frame['function'] . '()';
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private function isIgnored(string $file): bool
    {
        foreach ($this->ignoredFragments as $fragment) {
            if (str_contains($file, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
