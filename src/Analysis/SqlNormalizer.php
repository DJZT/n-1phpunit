<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Analysis;

/**
 * Reduces a statement to its shape so that
 *
 *     select * from "users" where "id" = 1
 *     select * from "users" where "id" = 2
 *
 * end up under the same key.
 */
final class SqlNormalizer
{
    public function normalize(string $sql): string
    {
        $sql = (string) preg_replace('/--[^\r\n]*/', ' ', $sql);
        $sql = (string) preg_replace('#/\*.*?\*/#s', ' ', $sql);
        // String literals, including the doubled-quote escape form.
        $sql = (string) preg_replace("/'(?:[^']|'')*'/", '?', $sql);
        // Numeric literals, but never a digit that is part of an identifier (users_2024, col1).
        $sql = (string) preg_replace('/(?<![\w.$])\d+(?:\.\d+)?(?![\w.])/', '?', $sql);
        $sql = (string) preg_replace('/\s+/', ' ', $sql);
        // "in (?, ?, ?)" and "in (?)" describe the same access pattern.
        $sql = (string) preg_replace('/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'in (?)', $sql);
        // "values (?, ?), (?, ?)" -> "values (?)"
        $sql = (string) preg_replace('/\bvalues\s*\((?:\s*\?\s*,?)+\)(?:\s*,\s*\((?:\s*\?\s*,?)+\))*/i', 'values (?)', $sql);

        return strtolower(trim($sql));
    }
}
