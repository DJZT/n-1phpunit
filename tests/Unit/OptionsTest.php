<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Unit;

use NPlusOne\PHPUnit\Configuration\Options;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;

#[CoversClass(Options::class)]
final class OptionsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('NPLUSONE_THRESHOLD');
    }

    public function test_it_reads_parameters_from_the_xml_configuration(): void
    {
        $options = Options::fromParameters(ParameterCollection::fromArray([
            'threshold' => '5',
            'limit' => '3',
            'filter' => '~Http~',
            'include-duplicates' => 'false',
            'report-file' => 'build/n-plus-one.json',
            'colors' => 'false',
        ]));

        self::assertTrue($options->enabled);
        self::assertSame(5, $options->threshold);
        self::assertSame(3, $options->limit);
        self::assertSame('~Http~', $options->filter);
        self::assertFalse($options->includeDuplicates);
        self::assertSame('build/n-plus-one.json', $options->reportFile);
        self::assertFalse($options->colors);
    }

    public function test_environment_variables_win_over_the_xml_configuration(): void
    {
        putenv('NPLUSONE_THRESHOLD=9');

        $options = Options::fromParameters(ParameterCollection::fromArray(['threshold' => '3']));

        self::assertSame(9, $options->threshold);
    }

    public function test_the_threshold_can_never_drop_below_two(): void
    {
        $options = Options::fromParameters(ParameterCollection::fromArray(['threshold' => '1']));

        self::assertSame(2, $options->threshold);
    }

    public function test_the_default_filter_only_matches_feature_tests(): void
    {
        $options = new Options();

        self::assertTrue($options->appliesTo('Tests\Feature\OrderTest::test_index'));
        self::assertTrue($options->appliesTo('/app/tests/Feature/OrderTest.php'));
        self::assertFalse($options->appliesTo('Tests\Unit\OrderTest::test_total'));
        self::assertFalse($options->appliesTo('Tests\Unit\FeatureFlagTest::test_flag'));
    }

    public function test_an_empty_filter_analyses_every_test(): void
    {
        $options = new Options(filter: '');

        self::assertTrue($options->appliesTo('Tests\Unit\OrderTest::test_total'));
    }

    public function test_ignored_statements_are_matched_against_the_normalised_sql(): void
    {
        $options = Options::fromParameters(ParameterCollection::fromArray([
            'ignore-sql' => '~^select \* from "?migrations~|||~^savepoint~',
        ]));

        self::assertTrue($options->isIgnoredSql('select * from "migrations" where batch = ?'));
        self::assertTrue($options->isIgnoredSql('savepoint trans?'));
        self::assertFalse($options->isIgnoredSql('select * from users where id = ?'));
    }
}
