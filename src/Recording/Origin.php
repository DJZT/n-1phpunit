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

    public function relative(string $basePath): string
    {
        if ($this->isUnknown()) {
            return 'unknown location';
        }

        $file = $this->file;
        if ($basePath !== '' && str_starts_with($file, $basePath . '/')) {
            $file = substr($file, strlen($basePath) + 1);
        }

        return $file . ':' . $this->line;
    }

    public function describe(string $basePath): string
    {
        $location = $this->relative($basePath);

        return $this->function === null ? $location : $location . ' (' . $this->function . ')';
    }
}
