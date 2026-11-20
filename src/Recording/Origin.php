<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Recording;

/**
 * The application call site a query was issued from.
 */
final class Origin
{
    /**
     * @param ?string $via The framework call that actually issued the query, when the
     *                     loop lives inside vendor code (a lazy relation, a validation
     *                     rule) rather than in the application's own loop.
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly ?string $function = null,
        public readonly ?string $via = null,
    ) {
    }

    public static function unknown(): self
    {
        return new self('', 0, null, null);
    }

    public function isUnknown(): bool
    {
        return $this->file === '';
    }

    public function key(): string
    {
        return $this->isUnknown() ? 'unknown' : $this->file . ':' . $this->line;
    }

    /**
     * The file alone, for consumers that address files rather than lines —
     * GitLab's Code Quality report, for one.
     */
    public function relativeFile(string $basePath): string
    {
        if ($this->isUnknown()) {
            return '';
        }

        if ($basePath !== '' && str_starts_with($this->file, $basePath . '/')) {
            return substr($this->file, strlen($basePath) + 1);
        }

        return $this->file;
    }

    public function relative(string $basePath): string
    {
        if ($this->isUnknown()) {
            return 'unknown location';
        }

        return $this->relativeFile($basePath) . ':' . $this->line;
    }

    public function describe(string $basePath): string
    {
        $location = $this->relative($basePath);

        return $this->function === null ? $location : $location . ' (' . $this->function . ')';
    }
}
