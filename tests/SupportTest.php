<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Hampel\Linode\Api\ApiError;
use Hampel\Linode\Api\Entity\Domain;
use Hampel\Linode\Api\Entity\DomainRecord;
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
     * The rounding table, measured against the live API on 12 September 2026 by writing each
     * value to a real domain record and reading back what was stored.
     *
     * It contradicts Linode's own documentation twice, which is why it is pinned here as a
     * table rather than derived from prose: the specification describes a record's ttl_sec as
     * rounded to the NEAREST valid value off a list starting at 300, and it is neither - it
     * rounds UP, off the same list the zone fields use, starting at 30.
     */
    public static function ttlCases(): \Generator
    {
        yield 'zero stays zero' => [0, 0];
        yield 'below the floor' => [1, 30];
        yield 'the floor itself' => [30, 30];
        yield 'the documentation said 300 here' => [60, 120];
        yield 'on the list' => [120, 120];
        yield 'on the list, higher' => [300, 300];
        yield 'rounds up, not to the nearest' => [900, 3600];
        yield 'nearer the one above' => [3000, 3600];
        yield 'one past a value' => [86401, 172800];
        yield 'past the ceiling, capped' => [2419201, 2419200];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ttlCases')]
    public function test_the_measured_rounding_table(int $asked, int $stored): void
    {
        $this->assertSame($stored, Ttl::round($asked));
    }

    /**
     * One rule, because the API has one - measured for a record and for a zone in the same
     * run. A second rule existed here until the harness contradicted it.
     */
    public function test_a_record_and_a_zone_round_the_same_way(): void
    {
        $this->assertSame(120, Ttl::round(60));
        $this->assertSame(120, DomainRecord::a('www', '203.0.113.1')->withTtl(60)->effectiveTtl());
        $this->assertSame(120, Domain::master('a.example', 'h@a.example')->withTtl(60)->effectiveTtl());
    }

    /**
     * A record's zero inherits the ZONE's TTL - measured on 2026-09-13 by moving a live zone
     * from 3600 to 7200 and watching its `ttl_sec: 0` records follow, read off the
     * authoritative nameserver. A fixed 86400 would not have moved.
     */
    public function test_a_records_zero_ttl_inherits_the_zone(): void
    {
        $record = DomainRecord::a('www', '203.0.113.1')->withTtl(0);
        $zone = Domain::master('example.com', 'h@example.com')->withTtl(3600);

        $this->assertSame(3600, $record->effectiveTtl($zone));
        $this->assertSame(7200, $record->effectiveTtl(7200), 'the zone ttl_sec on its own will do');
    }

    /**
     * A zone whose own ttl_sec is 0 falls back to 86400 - also measured, and the one claim in
     * this area the documentation got right. So a record inheriting from such a zone gets it.
     */
    public function test_a_record_inheriting_from_a_zone_that_is_itself_defaulted(): void
    {
        $record = DomainRecord::a('www', '203.0.113.1')->withTtl(0);

        $this->assertSame(86400, $record->effectiveTtl(Domain::master('a.example', 'h@a.example')));
        $this->assertSame(86400, $record->effectiveTtl(0));
    }

    /**
     * Without the zone it is still null, because the record genuinely does not know. Unchanged
     * from before the inheritance was measured, so an existing call behaves as it did.
     */
    public function test_a_records_zero_ttl_is_null_when_the_zone_is_not_supplied(): void
    {
        $this->assertNull(DomainRecord::a('www', '203.0.113.1')->withTtl(0)->effectiveTtl());
        $this->assertNull(DomainRecord::a('www', '203.0.113.1')->effectiveTtl());
    }

    /**
     * A record with its own TTL ignores the zone entirely.
     */
    public function test_an_explicit_record_ttl_is_not_inherited_from(): void
    {
        $record = DomainRecord::a('www', '203.0.113.1')->withTtl(300);

        $this->assertSame(300, $record->effectiveTtl());
        $this->assertSame(300, $record->effectiveTtl(Domain::master('a.example', 'h@a.example')->withTtl(7200)));
    }

    /**
     * A ZONE's zero IS documented, and differs per field.
     */
    public function test_zero_means_the_zone_fields_own_default(): void
    {
        $this->assertSame(86400, Ttl::effective('ttl_sec', 0));
        $this->assertSame(14400, Ttl::effective('refresh_sec', 0));
        $this->assertSame(14400, Ttl::effective('retry_sec', 0));
        $this->assertSame(1209600, Ttl::effective('expire_sec', 0));
        $this->assertSame(300, Ttl::effective('ttl_sec', 300));
        $this->assertSame(86400, Domain::master('a.example', 'h@a.example')->withTtl(0)->effectiveTtl());
    }

    public function test_validity_is_membership_of_the_one_list(): void
    {
        $this->assertTrue(Ttl::isValid(120));
        $this->assertTrue(Ttl::isValid(300));
        $this->assertFalse(Ttl::isValid(61));
        $this->assertFalse(Ttl::isValid(900));
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

    /**
     * The form the live API actually sends, measured on 2026-09-13 with a two-scope token:
     * a single space, no comma.
     */
    public function test_the_measured_header_form_is_space_separated(): void
    {
        $scopes = Scopes::fromHeader('images:read_only volumes:read_only');

        $this->assertSame(['images:read_only', 'volumes:read_only'], $scopes->scopes);
    }

    /**
     * The comma forms are not what Linode sends, and parsing keeps accepting them on purpose.
     * Splitting on whitespace alone would fail silently if the API ever moved to a comma: the
     * header would become one scope with a comma in its name and `allows()` would answer false
     * for everything the token holds. This is the test that stops someone tightening it.
     */
    public function test_a_comma_form_would_still_parse_if_linode_ever_sent_one(): void
    {
        foreach (['a:read_write,b:read_only', 'a:read_write, b:read_only'] as $header) {
            $this->assertSame(['a:read_write', 'b:read_only'], Scopes::fromHeader($header)->scopes, $header);
        }
    }

    /**
     * What tightening would cost, stated as an assertion rather than as a comment: a header
     * split the wrong way yields one nonsense scope and a token that appears to hold nothing.
     */
    public function test_an_unsplit_header_would_report_a_token_as_holding_nothing_it_has(): void
    {
        $mangled = Scopes::fromHeader('images:read_onlyvolumes:read_only');

        $this->assertFalse($mangled->allows('images:read_only'));
        $this->assertFalse($mangled->isUnknown(), 'and it would not even look unanswered');
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
