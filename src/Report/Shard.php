<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Report;

/**
 * Identifies the process that is writing a report.
 *
 * Under `php artisan test --parallel` / paratest every worker is a separate
 * PHP process running the same phpunit.xml, so without a per-process name the
 * workers would all write over one file.
 */
final class Shard
{
    /** Set by paratest (and therefore by Laravel's parallel test runner). */
    private const TOKEN_VARS = [
        'UNIQUE_TEST_TOKEN',
        'TEST_TOKEN',
        'LARAVEL_PARALLEL_TESTING_TOKEN',
        'PARATEST_TOKEN',
    ];

    /** Variables that mark a parallel run even when no token is exported. */
    private const PARALLEL_MARKERS = [
        'LARAVEL_PARALLEL_TESTING',
        'PARATEST',
    ];

    public function __construct(
        private readonly ?string $token,
        private readonly int $pid,
    ) {
    }

    public static function current(): self
    {
        return new self(self::detectToken(), self::detectPid());
    }

    /**
     * True when this process is one worker of several, i.e. when a report path
     * has to be made unique even if the user did not ask for it.
     */
    public function isParallel(): bool
    {
        return $this->token !== null;
    }

    /**
     * The name this process writes under: the runner's own token when there is
     * one, so report files line up with workers, and the pid otherwise.
     */
    public function id(): string
    {
        return $this->token ?? (string) $this->pid;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    private static function detectToken(): ?string
    {
        foreach (self::TOKEN_VARS as $name) {
            $token = self::sanitize(getenv($name));

            if ($token !== null) {
                return $token;
            }
        }

        foreach (self::PARALLEL_MARKERS as $name) {
            $marker = getenv($name);

            if (is_string($marker) && $marker !== '' && $marker !== '0' && strtolower($marker) !== 'false') {
                return (string) self::detectPid();
            }
        }

        return null;
    }

    private static function sanitize(string|false $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $value);

        return $value === '' ? null : $value;
    }

    private static function detectPid(): int
    {
        $pid = getmypid();

        return $pid === false ? 0 : $pid;
    }
}
