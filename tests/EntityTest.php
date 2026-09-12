<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Hampel\Linode\Api\Entity\Domain;
use Hampel\Linode\Api\Entity\DomainRecord;
use Hampel\Linode\Api\Enum\CaaTag;
use Hampel\Linode\Api\Enum\DomainStatus;
use Hampel\Linode\Api\Enum\DomainType;
use Hampel\Linode\Api\Enum\RecordType;
use Hampel\Linode\Api\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class EntityTest extends BaseTestCase
{
    public function test_a_master_domain_needs_an_soa_email(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SOA email');

        Domain::master('example.com', '  ');
    }

    public function test_a_slave_domain_needs_somewhere_to_transfer_from(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('master IP');

        Domain::slave('example.com', []);
    }

    public function test_a_domain_needs_a_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Domain('  ', DomainType::Master);
    }

    /**
     * A domain built from scratch sends only what was set, which is what makes an update
     * partial rather than a whole-object overwrite.
     */
    public function test_only_the_fields_that_were_set_are_sent(): void
    {
        $this->assertSame([
            'domain' => 'example.com',
            'type' => 'master',
            'soa_email' => 'hostmaster@example.com',
        ], Domain::master('example.com', 'hostmaster@example.com')->toArray());
    }

    public function test_the_withers_are_immutable_and_accumulate(): void
    {
        $base = Domain::master('example.com', 'hostmaster@example.com');
        $changed = $base->withTtl(300)->withDescription('primary zone')->withTags(['a', 'b']);

        $this->assertArrayNotHasKey('ttl_sec', $base->toArray());
        $this->assertSame(300, $changed->toArray()['ttl_sec']);
        $this->assertSame('primary zone', $changed->toArray()['description']);
        $this->assertSame(['a', 'b'], $changed->toArray()['tags']);
    }

    public function test_the_four_intervals_can_be_set_together(): void
    {
        $domain = Domain::master('example.com', 'h@example.com')
            ->withIntervals(ttl: 300, refresh: 3600, retry: 7200, expire: 604800);

        $payload = $domain->toArray();

        $this->assertSame(300, $payload['ttl_sec']);
        $this->assertSame(3600, $payload['refresh_sec']);
        $this->assertSame(7200, $payload['retry_sec']);
        $this->assertSame(604800, $payload['expire_sec']);
    }

    public function test_an_interval_left_out_of_withintervals_is_untouched(): void
    {
        $domain = Domain::master('example.com', 'h@example.com')->withTtl(300)->withIntervals(refresh: 3600);

        $this->assertSame(300, $domain->toArray()['ttl_sec']);
        $this->assertSame(3600, $domain->toArray()['refresh_sec']);
    }

    /**
     * An empty list is a value here, not an absence: it is how every AXFR host is removed.
     */
    public function test_an_empty_axfr_list_from_the_api_is_still_sent(): void
    {
        $domain = Domain::fromArray([
            'id' => 1,
            'domain' => 'example.com',
            'type' => 'master',
            'axfr_ips' => [],
            'tags' => [],
            'master_ips' => [],
        ]);

        $payload = $domain->toArray();

        $this->assertSame([], $payload['axfr_ips']);
        $this->assertSame([], $payload['tags']);
        $this->assertArrayNotHasKey('id', $payload, 'id is read-only and lives in the URL');
    }

    public function test_a_domain_built_from_scratch_omits_lists_it_was_never_given(): void
    {
        $payload = Domain::master('example.com', 'h@example.com')->toArray();

        $this->assertArrayNotHasKey('axfr_ips', $payload);
        $this->assertArrayNotHasKey('tags', $payload);
    }

    public function test_disabling_and_reactivating_a_zone(): void
    {
        $domain = Domain::master('example.com', 'h@example.com');

        $this->assertSame('disabled', $domain->disabled()->toArray()['status']);
        $this->assertSame('active', $domain->disabled()->active()->toArray()['status']);
        $this->assertTrue($domain->disabled()->active()->isActive());
        $this->assertTrue($domain->isMaster());
    }

    public function test_an_unknown_status_from_the_api_is_null_rather_than_a_guess(): void
    {
        $domain = Domain::fromArray(['domain' => 'example.com', 'type' => 'master', 'status' => 'something-new']);

        $this->assertNull($domain->status);
        $this->assertFalse($domain->isActive());
    }

    public function test_an_effective_ttl_resolves_linodes_rounding_and_its_zero_default(): void
    {
        $this->assertSame(86400, Domain::master('a.example', 'h@a.example')->withTtl(0)->effectiveTtl());
        $this->assertSame(120, Domain::master('a.example', 'h@a.example')->withTtl(60)->effectiveTtl());
        $this->assertSame(300, Domain::master('a.example', 'h@a.example')->withTtl(300)->effectiveTtl());
    }

    /**
     * The rule is the same for both, measured. See SupportTest for the whole table.
     */
    public function test_a_records_ttl_rounds_up_like_a_zones(): void
    {
        $this->assertSame(120, DomainRecord::a('www', '203.0.113.1')->withTtl(60)->effectiveTtl());
        $this->assertSame(3600, DomainRecord::a('www', '203.0.113.1')->withTtl(900)->effectiveTtl());
        $this->assertNull(DomainRecord::a('www', '203.0.113.1')->withTtl(0)->effectiveTtl());
    }

    public function test_every_record_type_has_a_constructor_that_takes_what_it_needs(): void
    {
        $this->assertSame(
            ['type' => 'A', 'name' => 'www', 'target' => '203.0.113.10'],
            DomainRecord::a('www', '203.0.113.10')->toArray()
        );

        $this->assertSame(
            ['type' => 'AAAA', 'name' => 'www', 'target' => '2001:db8::1'],
            DomainRecord::aaaa('www', '2001:db8::1')->toArray()
        );

        $this->assertSame(
            ['type' => 'CNAME', 'name' => 'shop', 'target' => 'shops.example.net'],
            DomainRecord::cname('shop', 'shops.example.net')->toArray()
        );

        $this->assertSame(
            ['type' => 'NS', 'name' => '', 'target' => 'ns1.linode.com'],
            DomainRecord::ns('ns1.linode.com')->toArray()
        );

        $this->assertSame(
            ['type' => 'PTR', 'name' => '10', 'target' => 'www.example.com'],
            DomainRecord::ptr('10', 'www.example.com')->toArray()
        );
    }

    /**
     * RFC 7505: empty target, empty name, priority 0.
     */
    public function test_a_null_mx_record_is_all_empties(): void
    {
        $this->assertSame(
            ['type' => 'MX', 'name' => '', 'target' => '', 'priority' => 0],
            DomainRecord::nullMx()->toArray()
        );
    }

    /**
     * Linode prepends the underscore itself. The decorated form becomes `__sip` and matches
     * nothing, with no error - so it is refused here where it can still be explained.
     */
    public function test_an_srv_service_written_the_decorated_way_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Linode prepends the underscore itself');

        DomainRecord::srv('_sip', 'tcp', 'sip.example.com', 5060);
    }

    public function test_an_srv_protocol_written_the_decorated_way_is_refused_too(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DomainRecord::srv('sip', '_tcp', 'sip.example.com', 5060);
    }

    public function test_a_record_without_the_thing_it_points_at_is_refused(): void
    {
        foreach ([
            fn () => DomainRecord::a('www', ''),
            fn () => DomainRecord::cname('www', ' '),
            fn () => DomainRecord::txt('www', ''),
            fn () => DomainRecord::mx(''),
            fn () => DomainRecord::caa(CaaTag::Issue, ''),
        ] as $build) {
            try {
                $build();
                $this->fail('did not raise');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_priority_is_sent_only_where_it_means_something(): void
    {
        $this->assertArrayHasKey('priority', DomainRecord::mx('mail.example.com', 10)->toArray());
        $this->assertArrayNotHasKey('priority', DomainRecord::a('www', '203.0.113.1')->withPriority(10)->toArray());
    }

    public function test_a_tag_is_sent_only_on_a_caa(): void
    {
        $this->assertSame('issuewild', DomainRecord::caa(CaaTag::IssueWild, 'letsencrypt.org')->toArray()['tag']);
        $this->assertArrayNotHasKey('tag', DomainRecord::a('www', '203.0.113.1')->toArray());
    }

    public function test_a_record_type_knows_which_fields_it_uses(): void
    {
        $this->assertTrue(RecordType::MX->usesPriority());
        $this->assertTrue(RecordType::SRV->usesPriority());
        $this->assertFalse(RecordType::A->usesPriority());
        $this->assertTrue(RecordType::SRV->usesServiceFields());
        $this->assertTrue(RecordType::CAA->usesTag());
        $this->assertTrue(RecordType::AAAA->isAddress());
        $this->assertFalse(RecordType::CNAME->isAddress());
    }

    public function test_a_name_is_relative_to_the_zone_and_the_apex_is_empty(): void
    {
        $record = DomainRecord::a('www', '203.0.113.1');

        $this->assertSame('www.example.com', $record->fqdn('example.com'));
        $this->assertSame('www.example.com', $record->fqdn('example.com.'));
        $this->assertSame('example.com', DomainRecord::mx('mail.example.com')->fqdn('example.com'));
    }

    public function test_an_unknown_record_type_from_the_api_does_not_fatal(): void
    {
        $record = DomainRecord::fromArray(['type' => 'SSHFP', 'name' => 'a', 'target' => 'b']);

        $this->assertSame(RecordType::A, $record->type, 'falls back rather than throwing on a read');
    }

    public function test_an_entity_serialises_to_what_the_api_sent(): void
    {
        $row = ['id' => 1, 'domain' => 'example.com', 'type' => 'master', 'something_new' => 'kept'];

        $this->assertSame($row, Domain::fromArray($row)->jsonSerialize());
        $this->assertSame('kept', Domain::fromArray($row)->raw['something_new']);
    }

    public function test_an_entity_built_by_hand_serialises_to_its_payload(): void
    {
        $this->assertSame(
            ['domain' => 'example.com', 'type' => 'master', 'soa_email' => 'h@example.com'],
            Domain::master('example.com', 'h@example.com')->jsonSerialize()
        );
    }

    public function test_the_filterable_fields_are_the_ones_the_api_declares(): void
    {
        $this->assertSame(['domain', 'group', 'tags'], Domain::FILTERABLE);
        $this->assertSame(['name', 'target', 'type', 'tag'], DomainRecord::FILTERABLE);
    }

    public function test_status_and_type_read_back_as_enums(): void
    {
        $domain = Domain::fromArray(['domain' => 'a.example', 'type' => 'slave', 'status' => 'disabled']);

        $this->assertSame(DomainType::Slave, $domain->type);
        $this->assertSame(DomainStatus::Disabled, $domain->status);
        $this->assertFalse($domain->isMaster());
    }
}
