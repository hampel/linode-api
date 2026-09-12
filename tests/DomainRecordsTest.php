<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Hampel\Linode\Api\Entity\DomainRecord;
use Hampel\Linode\Api\Enum\CaaTag;
use Hampel\Linode\Api\Enum\RecordType;
use Hampel\Linode\Api\Exception\NotFoundException;

final class DomainRecordsTest extends TestCase
{
    /**
     * A record row with every field the API returns - including the four that are only
     * meaningful on an SRV and come back regardless.
     *
     * @return array<string, mixed>
     */
    private function row(string $name = 'www', string $type = 'A', int $id = 5678): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'target' => '203.0.113.10',
            'priority' => 0,
            'weight' => 0,
            'port' => 0,
            'service' => null,
            'protocol' => null,
            'ttl_sec' => 300,
            'tag' => null,
            'created' => '2026-09-12T04:11:00',
            'updated' => '2026-09-12T04:11:00',
        ];
    }

    public function test_it_lists_the_records_of_a_zone(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row(), $this->row('mail', 'MX', 5679)]));

        $page = $this->linode()->records()->list(1234);

        $this->assertCount(2, $page);
        $this->assertSame('/v4/domains/1234/records', $this->sentPath());
        $this->assertSame(RecordType::A, $page->items[0]->type);
        $this->assertSame(RecordType::MX, $page->items[1]->type);
    }

    public function test_the_bound_form_supplies_the_domain_id(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));

        $records = $this->linode()->domains()->records(1234);
        $records->all();

        $this->assertSame(1234, $records->domainId);
        $this->assertSame('/v4/domains/1234/records', $this->sentPath());
    }

    public function test_the_bound_form_forwards_a_create(): void
    {
        $this->client->pushJson(200, $this->row());

        $this->linode()->domains()->records(1234)->create(DomainRecord::a('www', '203.0.113.10'));

        $this->assertSame('/v4/domains/1234/records', $this->sentPath());
        $this->assertSame(['type' => 'A', 'name' => 'www', 'target' => '203.0.113.10'], $this->sentBody());
    }

    public function test_filtering_by_type_uses_the_filter_header(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row('mail', 'MX')]));

        $records = $this->linode()->records()->ofType(1234, RecordType::MX);

        $this->assertCount(1, $records);
        $this->assertSame(['type' => 'MX'], $this->sentFilter());
    }

    public function test_finding_every_record_with_one_name(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));

        $this->linode()->records()->named(1234, 'www');

        $this->assertSame(['name' => 'www'], $this->sentFilter());
    }

    public function test_one_record_by_id(): void
    {
        $this->client->pushJson(200, $this->row());

        $record = $this->linode()->records()->get(1234, 5678);

        $this->assertSame(5678, $record->id);
        $this->assertSame('/v4/domains/1234/records/5678', $this->sentPath());
    }

    public function test_a_missing_record_is_null_from_find_and_raises_from_get(): void
    {
        $this->client->pushJson(404, $this->errors([['reason' => 'Not found']]));
        $this->assertNull($this->linode()->records()->find(1234, 5678));

        $this->client->pushJson(404, $this->errors([['reason' => 'Not found']]));
        $this->expectException(NotFoundException::class);
        $this->linode()->records()->get(1234, 5678);
    }

    /**
     * The record's dates arrive with no timezone at all - `2026-09-12T04:11:00` - and are
     * UTC. Read in PHP's own default timezone they would be a different instant, silently.
     */
    public function test_a_record_date_is_read_as_utc_whatever_the_process_timezone_is(): void
    {
        $was = date_default_timezone_get();
        date_default_timezone_set('Australia/Sydney');

        try {
            $this->client->pushJson(200, $this->row());

            $record = $this->linode()->records()->get(1234, 5678);

            $this->assertNotNull($record->created);
            $this->assertSame('2026-09-12T04:11:00+00:00', $record->created->format('c'));
            $this->assertSame('UTC', $record->created->getTimezone()->getName());
        } finally {
            date_default_timezone_set($was);
        }
    }

    public function test_an_update_strips_the_type_because_linode_will_not_take_it(): void
    {
        $this->client->pushJson(200, $this->row());

        $record = DomainRecord::fromArray($this->row());
        $this->linode()->records()->update(1234, 5678, $record->withTtl(3600));

        $body = $this->sentBody();

        $this->assertArrayNotHasKey('type', $body);
        $this->assertSame(3600, $body['ttl_sec']);
        $this->assertSame('/v4/domains/1234/records/5678', $this->sentPath());
    }

    public function test_a_type_passed_in_a_raw_array_is_stripped_too(): void
    {
        $this->client->pushJson(200, $this->row());

        $this->linode()->records()->update(1234, 5678, ['type' => 'CNAME', 'target' => 'elsewhere.example']);

        $this->assertSame(['target' => 'elsewhere.example'], $this->sentBody());
    }

    public function test_a_delete_returns_nothing(): void
    {
        $this->client->pushJson(200, []);

        $this->linode()->records()->delete(1234, 5678);

        $this->assertSame('DELETE', $this->client->lastRequest()->getMethod());
        $this->assertSame('/v4/domains/1234/records/5678', $this->sentPath());
    }

    /**
     * A record read back from the API carries service, protocol, port and weight as nulls
     * and zeroes whatever its type is. Echoing one straight into a create would send four
     * fields Linode says are only valid for SRV.
     */
    public function test_a_fetched_a_record_recreated_elsewhere_does_not_carry_srv_fields(): void
    {
        $this->client->pushJson(200, $this->row());
        $this->client->pushJson(200, $this->row());

        $record = $this->linode()->records()->get(1234, 5678);
        $this->linode()->records()->create(4321, $record);

        $this->assertSame([
            'type' => 'A',
            'name' => 'www',
            'target' => '203.0.113.10',
            'ttl_sec' => 300,
        ], $this->sentBody());
    }

    public function test_an_srv_create_sends_the_service_fields_and_no_name(): void
    {
        $this->client->pushJson(200, $this->row('_sip._tcp', 'SRV'));

        $this->linode()->records()->create(1234, DomainRecord::srv(
            'sip',
            'tcp',
            'sip.example.com',
            port: 5060,
            priority: 10,
            weight: 5
        ));

        $body = $this->sentBody();

        $this->assertArrayNotHasKey('name', $body, 'Linode composes an SRV name itself');
        $this->assertSame('sip', $body['service']);
        $this->assertSame('tcp', $body['protocol']);
        $this->assertSame(5060, $body['port']);
        $this->assertSame(5, $body['weight']);
        $this->assertSame(10, $body['priority']);
    }

    public function test_an_mx_create_sends_a_priority_and_a_caa_sends_a_tag(): void
    {
        $this->client->pushJson(200, $this->row('', 'MX'));
        $this->linode()->records()->create(1234, DomainRecord::mx('mail.example.com', priority: 10));

        $this->assertSame([
            'type' => 'MX',
            'name' => '',
            'target' => 'mail.example.com',
            'priority' => 10,
        ], $this->sentBody());

        $this->client->pushJson(200, $this->row('', 'CAA'));
        $this->linode()->records()->create(1234, DomainRecord::caa(CaaTag::Issue, 'letsencrypt.org'));

        $this->assertSame([
            'type' => 'CAA',
            'name' => '',
            'target' => 'letsencrypt.org',
            'tag' => 'issue',
        ], $this->sentBody());
    }

    public function test_a_txt_record_keeps_its_value_verbatim(): void
    {
        $this->client->pushJson(200, $this->row('_dmarc', 'TXT'));

        $value = 'v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com';
        $this->linode()->records()->create(1234, DomainRecord::txt('_dmarc', $value));

        $this->assertSame($value, $this->sentBody()['target']);
    }

    public function test_walking_a_zones_records_across_pages(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row('a', 'A', 1)], page: 1, pages: 2, results: 2));
        $this->client->pushJson(200, $this->collection([$this->row('b', 'A', 2)], page: 2, pages: 2, results: 2));

        $records = iterator_to_array($this->linode()->records()->each(1234), false);

        $this->assertCount(2, $records);
        $this->assertCount(2, $this->client->requests);
    }
}
