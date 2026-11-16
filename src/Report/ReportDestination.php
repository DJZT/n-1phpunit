<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Report;

use NPlusOne\PHPUnit\Configuration\Options;

/**
 * Decides where this process writes its JSON report.
 *
 * Three ways to ask for one, in order of precedence:
 *
 *   report-dir=build/n-plus-one      -> build/n-plus-one/n-plus-one.<shard>.json
 *   report-file=build/run-{token}.json -> the placeholder is filled in
 *   report-file=build/run.json       -> as given, but a parallel worker adds
 *                                       ".<shard>" so workers never collide
 */
final class ReportDestination
{
    public const FILE_PREFIX = 'n-plus-one.';

    public function __construct(
        private readonly ?string $file,
        private readonly ?string $directory = null,
        private readonly Shard $shard = new Shard(null, 0),
    ) {
    }

    public static function fromOptions(Options $options, ?Shard $shard = null): self
    {
        return new self($options->reportFile, $options->reportDir, $shard ?? Shard::current());
    }

    public function path(): ?string
    {
        if ($this->directory !== null && $this->directory !== '') {
            return rtrim($this->directory, '/') . '/' . self::FILE_PREFIX . $this->shard->id() . '.json';
        }

        if ($this->file === null || $this->file === '') {
            return null;
        }

        if (str_contains($this->file, '{token}') || str_contains($this->file, '{pid}')) {
            return str_replace(
                ['{token}', '{pid}'],
                [$this->shard->id(), (string) $this->shard->pid()],
                $this->file,
            );
        }

        return $this->shard->isParallel() ? $this->withSuffix($this->file, $this->shard->id()) : $this->file;
    }

    private function withSuffix(string $path, string $suffix): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === '') {
            return $path . '.' . $suffix;
        }

        return substr($path, 0, -(strlen($extension) + 1)) . '.' . $suffix . '.' . $extension;
    }
}
