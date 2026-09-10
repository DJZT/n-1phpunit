<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Analysis\SqlNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlNormalizer::class)]
final class SqlNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function statements(): iterable
    {
        yield 'numeric literals' => [
            'select * from "users" where "id" = 42',
            'select * from "users" where "id" = ?',
        ];

        yield 'string literals' => [
            "select * from users where email = 'ada@example.com'",
            'select * from users where email = ?',
        ];

        yield 'escaped quotes inside literals' => [
            "select * from users where name = 'O''Brien'",
            'select * from users where name = ?',
        ];

        yield 'in lists of any size collapse' => [
            'select * from users where id in (1, 2, 3, 4)',
            'select * from users where id in (?)',
        ];

        yield 'multi row inserts collapse' => [
            'insert into users (name) values (?, ?), (?, ?)',
            'insert into users (name) values (?)',
        ];

        yield 'whitespace and case' => [
            "SELECT   *\n  FROM users\tWHERE id = ?",
            'select * from users where id = ?',
        ];

        yield 'comments are stripped' => [
            "select * /* cached */ from users -- trailing\n where id = ?",
            'select * from users where id = ?',
        ];

        yield 'digits inside identifiers survive' => [
            'select col1 from users_2024 where id = 7',
            'select col1 from users_2024 where id = ?',
        ];
    }

    #[DataProvider('statements')]
    public function test_it_reduces_a_statement_to_its_shape(string $sql, string $expected): void
    {
        self::assertSame($expected, (new SqlNormalizer())->normalize($sql));
    }

    public function test_queries_differing_only_in_bindings_share_a_shape(): void
    {
        $normalizer = new SqlNormalizer();

        self::assertSame(
            $normalizer->normalize('select * from "orders" where "user_id" = 1 limit 1'),
            $normalizer->normalize('select * from "orders" where "user_id" = 987 limit 1'),
        );
    }
}
