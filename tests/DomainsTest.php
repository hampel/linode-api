<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Hampel\Linode\Api\Endpoint\Domains;
use Hampel\Linode\Api\Entity\Domain;
use Hampel\Linode\Api\Enum\DomainStatus;
use Hampel\Linode\Api\Enum\DomainType;
use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Hampel\Linode\Api\Exception\NotFoundException;
use Hampel\Linode\Api\Exception\NotPermittedException;
use Hampel\Linode\Api\Exception\RuntimeException;
use Hampel\Linode\Api\Support\Filter;

final class DomainsTest extends TestCase
{
    /**
     * A domain row in the shape the specification describes, with every field the API
     * returns - including the ones a create would never send.
     *
     * @return array<string, mixed>
     */
    private function row(string $domain = 'example.com', int $id = 1234): array
    {
        return [
            'id' => $id,
            'domain' => $domain,
            'type' => 'master',
            'status' => 'active',
            'description' => null,
            'group' => null,
            'soa_email' => 'hostmaster@example.com',
            'retry_sec' => 0,
            'master_ips' => [],
            'axfr_ips' => [],
            'expire_sec' => 0,
            'refresh_sec' => 0,
            'ttl_sec' => 300,
            'tags' => ['production'],
        ];
    }

    public function test_it_lists_a_page_of_zones(): void
    {
        $this->client->pushJson(200, $this->collection(
            [$this->row(), $this->row('example.net', 1235)],
            page: 1,
            pages: 3,
            results: 247
        ));

        $page = $this->linode()->domains()->list();

        $this->assertCount(2, $page);
        $this->assertSame(247, $page->total, 'results is the total, not the page count');
        $this->assertSame(3, $page->lastPage);
        $this->assertTrue($page->hasMore());
        $this->assertSame('example.com', $page->items[0]->domain);
        $this->assertSame(DomainType::Master, $page->items[0]->type);
        $this->assertSame(DomainStatus::Active, $page->items[0]->status);
        $this->assertSame(['production'], $page->items[0]->tags);
        $this->assertSame('/v4/domains', $this->sentPath());
        $this->assertSame('page=1', $this->sentQuery());
    }

    public function test_each_walks_the_pages_and_stops_at_the_last_one(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row('a.example', 1)], page: 1, pages: 2, results: 2));
        $this->client->pushJson(200, $this->collection([$this->row('b.example', 2)], page: 2, pages: 2, results: 2));

        $domains = iterator_to_array($this->linode()->domains()->each(), false);

        $this->assertCount(2, $domains);
        $this->assertSame(['a.example', 'b.example'], array_map(fn (Domain $d): string => $d->domain, $domains));
        $this->assertCount(2, $this->client->requests, 'no wasted request past the last page');
        $this->assertSame('page=2', $this->client->requests[1]->getUri()->getQuery());
    }

    public function test_a_walk_stopped_early_stops_making_requests(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row('a.example', 1)], page: 1, pages: 9, results: 9));

        foreach ($this->linode()->domains()->each() as $domain) {
            $this->assertSame('a.example', $domain->domain);
            break;
        }

        $this->assertCount(1, $this->client->requests);
    }

    public function test_a_page_size_is_sent_and_validated_before_the_request(): void
    {
        $this->client->pushJson(200, $this->collection([]));
        $this->linode()->domains()->list(pageSize: 500);

        $this->assertSame('page_size=500&page=1', $this->sentQuery());
    }

    public function test_a_page_size_linode_would_refuse_never_leaves_the_process(): void
    {
        try {
            $this->linode()->domains()->list(pageSize: 1);
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('between 25 and 500', $e->getMessage());
            $this->assertSame([], $this->client->requests);
        }
    }

    public function test_a_default_page_size_from_the_config_applies_to_every_list(): void
    {
        $this->client->pushJson(200, $this->collection([]));
        $this->linode(new \Hampel\Linode\Api\Config(pageSize: 250))->domains()->list();

        $this->assertSame('page_size=250&page=1', $this->sentQuery());
    }

    public function test_one_zone_by_id(): void
    {
        $this->client->pushJson(200, $this->row());

        $domain = $this->linode()->domains()->get(1234);

        $this->assertSame(1234, $domain->id);
        $this->assertSame('example.com', $domain->domain);
        $this->assertSame('/v4/domains/1234', $this->sentPath());
    }

    public function test_a_missing_zone_raises_from_get_and_is_null_from_find(): void
    {
        $this->client->pushJson(404, $this->errors([['reason' => 'Not found']]));
        $this->client->pushJson(404, $this->errors([['reason' => 'Not found']]));

        $this->assertNull($this->linode()->domains()->find(1234));

        $this->expectException(NotFoundException::class);
        $this->linode()->domains()->get(1234);
    }

    /**
     * A credential that may not see a zone and a zone that is not there are different
     * problems, and find() absorbs only the second.
     */
    public function test_find_does_not_absorb_a_permission_failure(): void
    {
        $this->client->pushJson(403, $this->errors([['reason' => 'Unauthorized']]));

        $this->expectException(NotPermittedException::class);
        $this->linode()->domains()->find(1234);
    }

    public function test_it_finds_a_zone_by_name_through_the_filter_header(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()], results: 1));

        $domain = $this->linode()->domains()->findByName('example.com');

        $this->assertNotNull($domain);
        $this->assertSame(1234, $domain->id);
        $this->assertSame(['domain' => 'example.com'], $this->sentFilter());
        $this->assertSame('/v4/domains', $this->sentPath());
    }

    /**
     * DNS is not case-sensitive and Linode stores a zone lower-cased, so an exact filter on
     * the typed form would find nothing while looking like it should.
     */
    public function test_a_name_lookup_is_lower_cased_and_stripped_of_a_trailing_dot(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));
        $this->linode()->domains()->findByName('Example.COM.');

        $this->assertSame(['domain' => 'example.com'], $this->sentFilter());
    }

    /**
     * The dangerous failure is not a 404. It is the filter being silently ignored: a 200
     * carrying the first zone on the account, which read as "the zone you asked for" sends
     * every subsequent edit at somebody else's DNS.
     */
    public function test_a_lookup_that_answers_with_the_wrong_zone_is_null_not_that_zone(): void
    {
        $logger = new RecordingLogger();
        $this->client->pushJson(200, $this->collection([
            $this->row('somebody-else.example', 1),
            $this->row('another.example', 2),
        ], results: 247));

        $found = $this->linode(logger: $logger)->domains()->findByName('example.com');

        $this->assertNull($found);

        $context = $logger->contextFor('Linode answered a filtered domain lookup with something else');

        $this->assertNotNull($context, 'the mismatch is worth a line in the log');
        $this->assertSame('example.com', $context['asked_for']);
    }

    public function test_a_lookup_picks_the_match_out_of_a_page_that_carries_more(): void
    {
        $this->client->pushJson(200, $this->collection([
            $this->row('other.example', 1),
            $this->row('example.com', 1234),
        ]));

        $this->assertSame(1234, $this->linode()->domains()->findByName('example.com')?->id);
    }

    public function test_a_name_that_is_not_on_the_account_is_null(): void
    {
        $this->client->pushJson(200, $this->collection([], results: 0));

        $this->assertNull($this->linode()->domains()->findByName('not-ours.example'));
    }

    public function test_an_empty_name_is_refused_without_a_request(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->linode()->domains()->findByName('  .  ');
        } finally {
            $this->assertSame([], $this->client->requests);
        }
    }

    public function test_it_creates_a_master_zone_from_an_entity(): void
    {
        $this->client->pushJson(200, $this->row());

        $this->linode()->domains()->create(
            Domain::master('example.com', 'hostmaster@example.com')->withTtl(300)->withTags(['production'])
        );

        $this->assertSame('POST', $this->client->lastRequest()->getMethod());
        $this->assertSame('/v4/domains', $this->sentPath());
        $this->assertSame([
            'domain' => 'example.com',
            'type' => 'master',
            'soa_email' => 'hostmaster@example.com',
            'ttl_sec' => 300,
            'tags' => ['production'],
        ], $this->sentBody());
    }

    public function test_it_creates_a_slave_zone(): void
    {
        $this->client->pushJson(200, $this->row());

        $this->linode()->domains()->create(Domain::slave('example.com', ['203.0.113.1']));

        $this->assertSame([
            'domain' => 'example.com',
            'type' => 'slave',
            'master_ips' => ['203.0.113.1'],
        ], $this->sentBody());
    }

    public function test_an_array_is_accepted_for_a_field_the_entity_does_not_model(): void
    {
        $this->client->pushJson(200, $this->row());

        $this->linode()->domains()->create(['domain' => 'example.com', 'type' => 'master', 'something_new' => 1]);

        $this->assertSame(['domain' => 'example.com', 'type' => 'master', 'something_new' => 1], $this->sentBody());
    }

    public function test_an_update_built_from_scratch_sends_only_what_changed(): void
    {
        $this->client->pushJson(200, $this->row());

        $this->linode()->domains()->update(1234, ['ttl_sec' => 3600]);

        $this->assertSame('PUT', $this->client->lastRequest()->getMethod());
        $this->assertSame('/v4/domains/1234', $this->sentPath());
        $this->assertSame(['ttl_sec' => 3600], $this->sentBody());
    }

    public function test_disabling_a_zone_is_an_update_rather_than_a_delete(): void
    {
        $this->client->pushJson(200, $this->row() + ['status' => 'disabled']);
        $domain = Domain::fromArray($this->row());

        $this->linode()->domains()->update(1234, $domain->disabled());

        $this->assertSame('disabled', $this->sentBody()['status']);
    }

    public function test_a_delete_returns_nothing_and_answers_an_empty_object(): void
    {
        $this->client->pushJson(200, []);

        $this->linode()->domains()->delete(1234);

        $this->assertSame('DELETE', $this->client->lastRequest()->getMethod());
        $this->assertSame('/v4/domains/1234', $this->sentPath());
    }

    public function test_deleting_a_zone_that_is_not_there_raises(): void
    {
        $this->client->pushJson(404, $this->errors([['reason' => 'Not found']]));

        $this->expectException(NotFoundException::class);
        $this->linode()->domains()->delete(1234);
    }

    /**
     * A lookup answers a nullable Domain and Domain::$id is nullable in turn, so passing
     * `$zone->id` to a method wanting an int is a TypeError waiting on the one account that
     * does not have the zone. Passing the object puts that check in one place.
     */
    public function test_records_can_be_bound_with_the_domain_itself(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));
        $this->client->pushJson(200, $this->collection([]));

        $zone = $this->linode()->domains()->findByName('example.com');

        $this->assertNotNull($zone);
        $this->linode()->domains()->records($zone)->all();

        $this->assertSame('/v4/domains/1234/records', $this->sentPath());
    }

    public function test_binding_records_to_a_zone_that_was_never_created_says_so(): void
    {
        $local = Domain::master('example.com', 'h@example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no id');

        $this->linode()->domains()->records($local);
    }

    /**
     * The four methods that act on a zone you already hold take the zone itself.
     *
     * The friction this removes is in the type system rather than in the typing: findByName()
     * answers `?Domain` and `Domain::$id` is `?int` in its own right, so narrowing away the
     * first null does not narrow away the second, and a caller who has done the null check
     * correctly still meets `expects int, int|null given` at PHPStan level 10. Reported and
     * measured by the first consumer. PHPStan analyses this file, so these calls are the
     * assertion that the signatures accept a Domain.
     */
    public function test_the_consuming_methods_take_a_domain_as_readily_as_an_id(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));
        $zone = $this->linode()->domains()->findByName('example.com');

        $this->assertNotNull($zone);

        $this->client->pushJson(200, $this->row());
        $this->assertSame(1234, $this->linode()->domains()->update($zone, ['ttl_sec' => 300])->id);
        $this->assertSame('/v4/domains/1234', $this->sentPath());

        $this->client->pushJson(200, []);
        $this->linode()->domains()->delete($zone);
        $this->assertSame('/v4/domains/1234', $this->sentPath());

        $this->client->pushJson(200, ['zone_file' => ['; example.com']]);
        $this->assertCount(1, $this->linode()->domains()->zoneFile($zone));
        $this->assertSame('/v4/domains/1234/zone-file', $this->sentPath());

        $this->client->pushJson(200, $this->row('example.net', 9999));
        $this->assertSame(9999, $this->linode()->domains()->cloneTo($zone, 'example.net')->id);
        $this->assertSame('/v4/domains/1234/clone', $this->sentPath());
    }

    /**
     * get() and find() are NOT widened, deliberately. They produce a Domain; passing one in
     * would be a round trip to fetch what the caller already holds, and a uniform surface is
     * not worth inviting that. Asserted by reflection so the decision cannot drift back.
     */
    public function test_the_producing_methods_are_deliberately_not_widened(): void
    {
        foreach (['get', 'find'] as $method) {
            $type = (new \ReflectionMethod(Domains::class, $method))->getParameters()[0]->getType();

            $this->assertInstanceOf(\ReflectionNamedType::class, $type, $method);
            $this->assertSame('int', $type->getName(), $method . '() should take an id, not a zone');
        }
    }

    /**
     * A zone built locally has no id, so the widened methods raise where the object cannot
     * name a record at Linode - rather than sending "domains/" and getting a confusing 404.
     */
    public function test_a_zone_that_was_never_created_is_refused_by_the_widened_methods(): void
    {
        $local = Domain::master('example.com', 'h@example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no id');

        try {
            $this->linode()->domains()->delete($local);
        } finally {
            $this->assertSame([], $this->client->requests, 'and nothing was sent');
        }
    }

    public function test_it_imports_a_zone_by_transfer(): void
    {
        $this->client->pushJson(200, $this->row());

        $this->linode()->domains()->import('example.com', 'ns1.elsewhere.test');

        $this->assertSame('/v4/domains/import', $this->sentPath());
        $this->assertSame([
            'domain' => 'example.com',
            'remote_nameserver' => 'ns1.elsewhere.test',
        ], $this->sentBody());
    }

    public function test_it_clones_a_zone_under_a_new_name(): void
    {
        $this->client->pushJson(200, $this->row('example.net', 9999));

        $clone = $this->linode()->domains()->cloneTo(1234, 'example.net');

        $this->assertSame('/v4/domains/1234/clone', $this->sentPath());
        $this->assertSame(['domain' => 'example.net'], $this->sentBody());
        $this->assertSame(9999, $clone->id);
    }

    public function test_the_zone_file_comes_back_as_lines(): void
    {
        $this->client->pushJson(200, ['zone_file' => [
            '; example.com [1234]',
            '$TTL 300',
            '@  IN  SOA  ns1.linode.com. hostmaster.example.com. 2026091201 14400 14400 1209600 300',
        ]]);

        $lines = $this->linode()->domains()->zoneFile(1234);

        $this->assertCount(3, $lines);
        $this->assertSame('$TTL 300', $lines[1]);
        $this->assertSame('/v4/domains/1234/zone-file', $this->sentPath());
    }

    public function test_a_filter_and_an_order_reach_the_list_request(): void
    {
        $this->client->pushJson(200, $this->collection([]));

        $this->linode()->domains()->list(1, Filter::where('tags', 'production')->orderBy('domain'));

        $this->assertSame([
            'tags' => 'production',
            '+order_by' => 'domain',
            '+order' => 'asc',
        ], $this->sentFilter());
    }

    public function test_all_collects_every_page_into_a_list(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row('a.example', 1)], page: 1, pages: 2, results: 2));
        $this->client->pushJson(200, $this->collection([$this->row('b.example', 2)], page: 2, pages: 2, results: 2));

        $domains = $this->linode()->domains()->all();

        $this->assertCount(2, $domains);
        $this->assertSame(0, array_key_first($domains), 'a list, not a map');
    }
}
