<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Hampel\Linode\Api\ApiError;
use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Hampel\Linode\Api\Exception\RuntimeException;
use Hampel\Linode\Api\Result\Page;
use Hampel\Linode\Api\Result\Scopes;
use Hampel\Linode\Api\Support\Cast;
use Hampel\Linode\Api\Support\Json;
use Hampel\Linode\Api\Support\Psr17Discovery;
use Hampel\Linode\Api\Support\Ttl;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class SupportTest extends BaseTestCase
{
    public function test_cast_returns_null_rather_than_throwing_on_the_wrong_shape(): void
    {
        $this->assertSame('7', Cast::string(7));
        $this->assertNull(Cast::string(['a']));
        $this->assertSame(7, Cast::int('7'));
        $this->assertSame(-7, Cast::int('-7'));
        $this->assertNull(Cast::int('seven'));
        $this->assertSame(1.5, Cast::float('1.5'));
        $this->assertTrue(Cast::bool(1));
        $this->assertFalse(Cast::bool('0'));
        $this->assertNull(Cast::bool('maybe'));
        $this->assertSame(['a', '1'], Cast::strings(['a', 1, ['nested']]));
        $this->assertSame([], Cast::strings('not a list'));
    }

    /**
     * Linode's dates carry no timezone and are UTC. Read in the process timezone they are a
     * different instant, silently.
     */
    public function test_a_date_with_no_zone_is_read_as_utc(): void
    {
        $was = date_default_timezone_get();
        date_default_timezone_set('Australia/Sydney');

        try {
            $this->assertSame('2018-01-01T00:01:01+00:00', Cast::datetime('2018-01-01T00:01:01')?->format('c'));
        } finally {
            date_default_timezone_set($was);
        }
    }

    public function test_a_date_that_does_carry_a_zone_is_respected_and_normalised(): void
    {
        $this->assertSame('2018-01-01T00:01:01+00:00', Cast::datetime('2018-01-01T10:01:01+10:00')?->format('c'));
    }

    public function test_an_unparseable_date_is_null(): void
    {
        $this->assertNull(Cast::datetime('whenever'));
        $this->assertNull(Cast::datetime(''));
        $this->assertNull(Cast::datetime(12345));
    }

    public function test_json_encoding_a_thing_that_cannot_be_json_is_the_callers_mistake(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Json::encode(['bad' => "\xB1\x31"]);
    }

    public function test_json_decoding_something_that_is_not_json_is_null_rather_than_a_throw(): void
    {
        $this->assertNull(Json::decode('<html>'));
        $this->assertNull(Json::decode('   '));
        $this->assertNull(Json::decode('"a bare string"'));
        $this->assertSame(['a' => 1], Json::decode('{"a":1}'));
    }

    /**
     * The zone fields round UP; a record's ttl_sec rounds to the NEAREST, off a shorter list
     * that starts at 300. Both are the specification's own wording.
     */
    public function test_the_two_rounding_rules_are_not_the_same(): void
    {
        $this->assertSame(120, Ttl::round(60));
        $this->assertSame(300, Ttl::round(121));
        $this->assertSame(30, Ttl::round(1));
        $this->assertSame(0, Ttl::round(0));
        $this->assertSame(2419200, Ttl::round(99999999), 'nothing higher to round up to');

        $this->assertSame(300, Ttl::roundForRecord(60));
        $this->assertSame(300, Ttl::roundForRecord(1000));
        $this->assertSame(3600, Ttl::roundForRecord(3000));
        $this->assertSame(0, Ttl::roundForRecord(0));

        // 900 is the value the harness probes with, because the two rules disagree about it
        // in opposite directions - a zone rounds it up to 3600, a record down to 300.
        $this->assertSame(3600, Ttl::round(900));
        $this->assertSame(300, Ttl::roundForRecord(900));
    }

    public function test_zero_means_the_fields_own_default_not_no_caching(): void
    {
        $this->assertSame(86400, Ttl::effective('ttl_sec', 0));
        $this->assertSame(14400, Ttl::effective('refresh_sec', 0));
        $this->assertSame(14400, Ttl::effective('retry_sec', 0));
        $this->assertSame(1209600, Ttl::effective('expire_sec', 0));
        $this->assertSame(300, Ttl::effective('ttl_sec', 300));
    }

    public function test_validity_is_per_field_family(): void
    {
        $this->assertTrue(Ttl::isValid(120));
        $this->assertFalse(Ttl::isValidForRecord(120));
        $this->assertTrue(Ttl::isValidForRecord(300));
        $this->assertFalse(Ttl::isValid(61));
    }

    public function test_a_page_reads_linodes_envelope(): void
    {
        $page = Page::fromResponse([
            'data' => [['id' => 1], ['id' => 2]],
            'page' => 2,
            'pages' => 5,
            'results' => 247,
        ], static fn (array $row): mixed => $row['id'] ?? null);

        $this->assertSame([1, 2], $page->items);
        $this->assertSame(2, $page->currentPage);
        $this->assertSame(5, $page->lastPage);
        $this->assertSame(247, $page->total);
        $this->assertCount(2, $page, 'count is this page, total is everything');
        $this->assertTrue($page->hasMore());
        $this->assertFalse($page->isEmpty());
        $this->assertSame([1, 2], iterator_to_array($page));
        $this->assertSame('[1,2]', json_encode($page));
    }

    public function test_a_page_without_an_envelope_is_still_a_usable_single_page(): void
    {
        $page = Page::fromResponse(['data' => [['id' => 1]]], static fn (array $row): mixed => $row['id'] ?? null);

        $this->assertSame(1, $page->currentPage);
        $this->assertSame(1, $page->lastPage);
        $this->assertSame(1, $page->total);
        $this->assertFalse($page->hasMore());
    }

    public function test_a_page_of_nothing_is_empty_rather_than_broken(): void
    {
        $page = Page::fromResponse([], static fn (array $row): mixed => $row);

        $this->assertTrue($page->isEmpty());
        $this->assertSame(0, $page->total);
    }

    public function test_the_page_size_bounds_are_the_ones_the_api_enforces(): void
    {
        $this->assertSame(25, Page::MIN_SIZE);
        $this->assertSame(500, Page::MAX_SIZE);

        Page::assertValidPageSize(25);
        Page::assertValidPageSize(500);
        $this->addToAssertionCount(1);

        $this->expectException(InvalidArgumentException::class);
        Page::assertValidPageSize(501);
    }

    public function test_scopes_parse_whether_they_are_separated_by_spaces_or_commas(): void
    {
        foreach (['domains:read_write account:read_only', 'domains:read_write,account:read_only', 'domains:read_write, account:read_only'] as $header) {
            $scopes = Scopes::fromHeader($header);

            $this->assertSame(['domains:read_write', 'account:read_only'], $scopes->scopes, $header);
        }
    }

    public function test_read_write_satisfies_a_read_only_requirement_and_not_the_other_way(): void
    {
        $scopes = Scopes::fromHeader('domains:read_write');

        $this->assertTrue($scopes->allows('domains:read_write'));
        $this->assertTrue($scopes->allows('domains:read_only'));
        $this->assertTrue($scopes->allows('domains'), 'a bare area asks for any access');

        $readOnly = Scopes::fromHeader('domains:read_only');

        $this->assertTrue($readOnly->allows('domains:read_only'));
        $this->assertFalse($readOnly->allows('domains:read_write'));
    }

    public function test_a_star_holds_everything(): void
    {
        $scopes = Scopes::fromHeader('*');

        $this->assertTrue($scopes->isUnrestricted());
        $this->assertTrue($scopes->allows('anything:read_write'));
        $this->assertSame([], $scopes->missing(['a:read_only', 'b:read_write']));
    }

    /**
     * `unknown` is what the live API sends where there is no credential to describe. It is
     * not "no permissions" - it is "the question was not answered".
     */
    public function test_unknown_and_absent_both_mean_the_question_was_not_answered(): void
    {
        foreach (['unknown', 'UNKNOWN', ''] as $header) {
            $scopes = Scopes::fromHeader($header);

            $this->assertTrue($scopes->isUnknown(), $header);
            $this->assertFalse($scopes->allows('domains:read_only'), $header);
            $this->assertFalse($scopes->isUnrestricted(), $header);
        }
    }

    public function test_missing_names_what_is_absent_rather_than_what_was_asked(): void
    {
        $scopes = Scopes::fromHeader('domains:read_write');

        $this->assertSame(
            ['linodes:read_only', 'account:read_only'],
            $scopes->missing(['domains:read_only', 'linodes:read_only', 'account:read_only'])
        );
    }

    public function test_an_api_error_keeps_field_and_reason_apart(): void
    {
        $errors = ApiError::listFrom(['errors' => [
            ['field' => 'page_size', 'reason' => 'Must be 25-500'],
            ['reason' => 'Invalid Token'],
            ['field' => 'x'],
            'not an object',
        ]]);

        $this->assertCount(2, $errors, 'an entry with no reason is not an error we can report');
        $this->assertSame('page_size', $errors[0]->field);
        $this->assertSame('page_size: Must be 25-500', $errors[0]->describe());
        $this->assertNull($errors[1]->field);
        $this->assertSame('Invalid Token', $errors[1]->describe());
        $this->assertSame(['reason' => 'Invalid Token', 'field' => null], $errors[1]->jsonSerialize());
    }

    public function test_an_error_body_that_is_not_one_yields_nothing_rather_than_failing(): void
    {
        $this->assertSame([], ApiError::listFrom(null));
        $this->assertSame([], ApiError::listFrom(['errors' => 'nope']));
        $this->assertSame([], ApiError::listFrom([]));
    }

    public function test_psr17_discovery_finds_the_installed_factory(): void
    {
        [$request, $stream] = Psr17Discovery::find();

        $this->assertInstanceOf(\Psr\Http\Message\RequestFactoryInterface::class, $request);
        $this->assertInstanceOf(\Psr\Http\Message\StreamFactoryInterface::class, $stream);
    }

    public function test_psr17_discovery_says_what_to_do_when_it_finds_nothing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No PSR-17 factory');

        Psr17Discovery::from([['Nothing\AtAll', 'Nothing\AtAll']]);
    }
}
