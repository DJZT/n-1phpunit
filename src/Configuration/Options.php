<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Configuration;

use PHPUnit\Runner\Extension\ParameterCollection;

/**
 * Immutable configuration for the extension.
 *
 * Every value can be set through <parameter name="..." value="..."/> in
 * phpunit.xml and overridden at run time through an environment variable
 * (handy for CI: NPLUSONE_THRESHOLD=2 vendor/bin/phpunit).
 */
final class Options
{
    public const DEFAULT_FILTER = '~\bFeature\b~';

    /**
     * @param string $filter        PCRE matched against the test id (Tests\Feature\FooTest::testBar).
     *                              An empty string disables filtering (every test is analysed).
     * @param list<string> $ignoreSql PCRE list; a query whose normalised form matches is never reported.
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly int $threshold = 3,
        public readonly int $limit = 10,
        public readonly string $filter = self::DEFAULT_FILTER,
        public readonly bool $includeDuplicates = true,
        public readonly ?string $reportFile = null,
        public readonly int $sqlMaxLength = 160,
        public readonly array $ignoreSql = [],
        public readonly string $basePath = '',
        public readonly bool $colors = true,
    ) {
    }

    public static function fromParameters(ParameterCollection $parameters): self
    {
        $get = static function (string $name) use ($parameters): ?string {
            $env = getenv('NPLUSONE_' . strtoupper(str_replace('-', '_', $name)));
            if (is_string($env) && $env !== '') {
                return $env;
            }

            return $parameters->has($name) ? $parameters->get($name) : null;
        };

        $defaults = new self();

        $ignoreSql = $get('ignore-sql');

        return new self(
            enabled: self::toBool($get('enabled'), $defaults->enabled),
            threshold: max(2, (int) ($get('threshold') ?? $defaults->threshold)),
            limit: max(1, (int) ($get('limit') ?? $defaults->limit)),
            filter: $get('filter') ?? $defaults->filter,
            includeDuplicates: self::toBool($get('include-duplicates'), $defaults->includeDuplicates),
            reportFile: $get('report-file'),
            sqlMaxLength: max(20, (int) ($get('sql-max-length') ?? $defaults->sqlMaxLength)),
            ignoreSql: $ignoreSql === null ? [] : array_values(array_filter(array_map('trim', explode('|||', $ignoreSql)))),
            basePath: rtrim($get('base-path') ?? self::guessBasePath(), '/'),
            colors: self::toBool($get('colors'), self::supportsColors()),
        );
    }

    public function appliesTo(string $testId): bool
    {
        if ($this->filter === '') {
            return true;
        }

        return @preg_match($this->filter, $testId) === 1;
    }

    public function isIgnoredSql(string $normalizedSql): bool
    {
        foreach ($this->ignoreSql as $pattern) {
            if (@preg_match($pattern, $normalizedSql) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function toBool(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function guessBasePath(): string
    {
        $cwd = getcwd();

        return is_string($cwd) ? rtrim($cwd, '/') : '';
    }

    private static function supportsColors(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (!defined('STDOUT')) {
            return false;
        }

        return function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }
}
