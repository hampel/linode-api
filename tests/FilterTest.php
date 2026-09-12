<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Hampel\Linode\Api\Support\Filter;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class FilterTest extends BaseTestCase
{
    public function test_a_single_condition_is_written_at_the_top_level(): void
    {
        $this->assertSame(['domain' => 'example.com'], Filter::where('domain', 'example.com')->toArray());
    }

    /**
     * Sibling keys would be ANDed too, but two conditions on the SAME field would overwrite
     * each other - which is what makes the explicit form the safe one.
     */
    public function test_several_conditions_are_wrapped_in_and(): void
    {
        $filter = Filter::make()->greaterOrEqual('id', 10)->lessOrEqual('id', 20);

        $this->assertSame([
            '+and' => [
                ['id' => ['+gte' => 10]],
                ['id' => ['+lte' => 20]],
            ],
        ], $filter->toArray());
    }

    public function test_every_operator_writes_linodes_own_key(): void
    {
        $this->assertSame(['t' => ['+neq' => 'A']], Filter::make()->not('t', 'A')->toArray());
        $this->assertSame(['t' => ['+contains' => 'ex']], Filter::make()->contains('t', 'ex')->toArray());
        $this->assertSame(['n' => ['+gt' => 1]], Filter::make()->greaterThan('n', 1)->toArray());
        $this->assertSame(['n' => ['+gte' => 1]], Filter::make()->greaterOrEqual('n', 1)->toArray());
        $this->assertSame(['n' => ['+lt' => 1]], Filter::make()->lessThan('n', 1)->toArray());
        $this->assertSame(['n' => ['+lte' => 1]], Filter::make()->lessOrEqual('n', 1)->toArray());
    }

    public function test_ordering_sits_beside_the_conditions(): void
    {
        $filter = Filter::where('type', 'A')->orderBy('name', 'desc');

        $this->assertSame([
            'type' => 'A',
            '+order_by' => 'name',
            '+order' => 'desc',
        ], $filter->toArray());
    }

    public function test_ordering_defaults_to_ascending(): void
    {
        $this->assertSame(['+order_by' => 'name', '+order' => 'asc'], Filter::make()->orderBy('name')->toArray());
    }

    public function test_the_direction_can_be_flipped_after_the_fact(): void
    {
        $filter = Filter::make()->orderBy('name');

        $this->assertSame('desc', $filter->descending()->toArray()['+order']);
        $this->assertSame('asc', $filter->descending()->ascending()->toArray()['+order']);
    }

    public function test_a_direction_with_nothing_to_order_by_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('means nothing without a field');

        Filter::make()->descending();
    }

    public function test_an_unknown_direction_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Filter::make()->orderBy('name', 'sideways');
    }

    public function test_alternatives_become_an_or_of_criteria_objects(): void
    {
        $filter = Filter::anyOf(
            Filter::where('type', 'A'),
            Filter::where('type', 'AAAA'),
        );

        $this->assertSame([
            '+or' => [
                ['type' => 'A'],
                ['type' => 'AAAA'],
            ],
        ], $filter->toArray());
    }

    public function test_an_or_branch_of_several_conditions_keeps_its_own_and(): void
    {
        $filter = Filter::anyOf(
            Filter::make()->and('type', 'MX')->and('name', ''),
            Filter::where('type', 'A'),
        );

        $this->assertSame([
            '+or' => [
                ['+and' => [['type' => 'MX'], ['name' => '']]],
                ['type' => 'A'],
            ],
        ], $filter->toArray());
    }

    public function test_ordering_can_be_added_to_an_or(): void
    {
        $filter = Filter::anyOf(Filter::where('type', 'A'), Filter::where('type', 'MX'))->orderBy('name');

        $this->assertSame(['+or', '+order_by', '+order'], array_keys($filter->toArray()));
    }

    public function test_an_empty_or_produces_an_empty_filter(): void
    {
        $this->assertTrue(Filter::anyOf()->isEmpty());
        $this->assertTrue(Filter::anyOf(Filter::make())->isEmpty());
    }

    public function test_a_filter_is_immutable(): void
    {
        $base = Filter::where('type', 'A');
        $more = $base->and('name', 'www');

        $this->assertSame(['type' => 'A'], $base->toArray());
        $this->assertArrayHasKey('+and', $more->toArray());
    }

    public function test_an_empty_filter_has_nothing_to_send(): void
    {
        $this->assertTrue(Filter::make()->isEmpty());
        $this->assertFalse(Filter::where('a', 'b')->isEmpty());
        $this->assertFalse(Filter::make()->orderBy('a')->isEmpty());
    }

    public function test_the_header_is_the_encoded_filter(): void
    {
        $this->assertSame('{"domain":"example.com"}', Filter::where('domain', 'example.com')->toHeader());
        $this->assertSame('{"domain":"example.com"}', json_encode(Filter::where('domain', 'example.com')));
    }

    public function test_an_operator_passed_as_a_field_name_is_caught_here(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('filter operators, not a field name');

        Filter::where('+order_by', 'name');
    }

    public function test_an_empty_field_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Filter::where('  ', 'x');
    }

    public function test_a_field_name_is_trimmed(): void
    {
        $this->assertSame(['domain' => 'x'], Filter::where('  domain ', 'x')->toArray());
    }
}
