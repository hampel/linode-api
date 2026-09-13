<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Endpoint;

use Hampel\Linode\Api\Entity\Domain;
use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Hampel\Linode\Api\Result\Page;
use Hampel\Linode\Api\Support\Filter;

/**
 * DNS zones.
 *
 * https://techdocs.akamai.com/linode-api/reference/get-domains
 *
 * Reads need `domains:read_only`; everything that changes something needs
 * `domains:read_write`.
 *
 * A DOMAIN IS UNIQUE ACROSS THE WHOLE OF LINODE, not just across your account. Creating one
 * that another customer already holds is a 400, which is the API telling you something true
 * about the world rather than about your request - and it is the reason findByName() exists:
 * on an account of any size, the id is the thing you do not have and the name is the thing
 * you do.
 */
final class Domains extends Endpoint
{
    /**
     * One page of the account's zones.
     *
     * @return Page<Domain>
     */
    public function list(int $page = 1, ?Filter $filter = null, ?int $pageSize = null): Page
    {
        return $this->apiPaginate('domains', Domain::fromArray(...), $page, $filter, $pageSize);
    }

    /**
     * Every zone, a page at a time, fetched only as far as it is consumed.
     *
     * @return \Generator<int, Domain>
     */
    public function each(?Filter $filter = null, ?int $pageSize = null): \Generator
    {
        return $this->apiEach('domains', Domain::fromArray(...), $filter, $pageSize);
    }

    /**
     * Every zone, as a list.
     *
     * Fine for an account with tens of zones and a bad idea for one with thousands - it
     * holds them all in memory and makes every request before returning any of them. Use
     * each() where the count is unknown.
     *
     * @return list<Domain>
     */
    public function all(?Filter $filter = null): array
    {
        return iterator_to_array($this->each($filter), false);
    }

    /**
     * One zone by id. Raises NotFoundException when there is no such zone, or when this
     * token may not see it - Linode does not distinguish the two, and neither can this.
     */
    public function get(int $id): Domain
    {
        return Domain::fromArray($this->apiGet($this->path($id))->object());
    }

    /**
     * One zone by id, or null when it is not there.
     */
    public function find(int $id): ?Domain
    {
        return $this->apiFind($this->path($id), Domain::fromArray(...));
    }

    /**
     * One zone by NAME, or null.
     *
     * Filtering is a header on this API, not a query parameter, so this is `X-Filter` rather
     * than `?domain=`. The name is unique across Linode, so at most one thing comes back -
     * but it comes back inside a collection envelope, because the endpoint is the list one.
     *
     * The comparison is exact and case-sensitive at Linode's end; the name is lower-cased
     * here because DNS is not case-sensitive and a zone is stored lower-case, so
     * `Example.COM` would otherwise find nothing while looking like it should.
     *
     * THE NAME IS CHECKED AGAIN ON THE WAY BACK, which looks like belt and braces and is
     * not. This method's whole correctness rests on the server honouring `X-Filter`, and the
     * failure mode if it ever stopped is not an error - it is a 200 carrying the first zone
     * on the account, which this would return as "the zone called example.com". Everything
     * downstream then edits the wrong zone's DNS. A filter being ignored is a silent success,
     * so it is caught by confirming the answer rather than by trusting the request; the
     * `filtering` harness exercise is the other half of the same check.
     */
    public function findByName(string $domain): ?Domain
    {
        $domain = strtolower(trim($domain, ". \t\n\r\0\x0B"));

        if ($domain === '') {
            throw new InvalidArgumentException('A domain name is required to look one up.');
        }

        $page = $this->list(1, Filter::where('domain', $domain));

        foreach ($page->items as $candidate) {
            if (strtolower(trim($candidate->domain, '. ')) === $domain) {
                return $candidate;
            }
        }

        if (!$page->isEmpty()) {
            $this->logger->warning('Linode answered a filtered domain lookup with something else', [
                'asked_for' => $domain,
                'received' => array_map(static fn (Domain $item): string => $item->domain, $page->items),
                'results' => $page->total,
            ]);
        }

        return null;
    }

    /**
     * Create a zone.
     *
     *     $linode->domains()->create(
     *         Domain::master('example.com', 'hostmaster@example.com')->withTtl(300)
     *     );
     *
     * An array is accepted for a field this package does not model yet.
     *
     * @param  Domain|array<string, mixed>  $domain
     */
    public function create(Domain|array $domain): Domain
    {
        $payload = $domain instanceof Domain ? $domain->toArray() : $domain;

        $this->logger->info('Linode domain create', ['domain' => $payload['domain'] ?? null]);

        return Domain::fromArray($this->apiPost('domains', $payload)->object());
    }

    /**
     * Change a zone.
     *
     * PARTIAL, DESPITE BEING A PUT: only the fields in the payload are touched. Passing a
     * Domain read from the API sends every field it has, which is the same values it already
     * had; passing one built from scratch sends only what was set on it. Both work; the
     * second is the smaller request and the one that cannot overwrite a change somebody else
     * made in between.
     *
     * @param  Domain|array<string, mixed>  $changes
     */
    public function update(Domain|int $id, Domain|array $changes): Domain
    {
        $payload = $changes instanceof Domain ? $changes->toArray() : $changes;

        $this->logger->info('Linode domain update', [
            'id' => $this->zoneId($id),
            'fields' => array_keys($payload),
        ]);

        return Domain::fromArray($this->apiPut($this->path($id), $payload)->object());
    }

    /**
     * Delete a zone, and every record in it.
     *
     * There is no undo and no soft delete: the zone stops resolving as soon as the change
     * propagates. Disabling it - `$linode->domains()->update($id, $domain->disabled())` -
     * takes it out of service reversibly and is what you want more often than this.
     *
     * Returns nothing. Linode answers a successful delete with `{}` and a 200; a zone that
     * was not there raises NotFoundException rather than passing quietly, because "delete
     * something that does not exist" is far more often a wrong id than an idempotent retry.
     */
    public function delete(Domain|int $id): void
    {
        $this->logger->warning('Linode domain delete', ['id' => $this->zoneId($id)]);

        $this->apiDelete($this->path($id));
    }

    /**
     * Records within a zone, as a bound endpoint: every call knows its domain.
     *
     *     $linode->domains()->records($id)->all();
     *
     * The same operations are on `$linode->records()` with the domain id as the first
     * argument. This is the one to reach for when several calls concern one zone.
     *
     * Takes the Domain itself as readily as its id, because a lookup hands back a Domain and
     * its `$id` is nullable - a zone built locally has not got one. Passing the object rather
     * than `$zone->id` keeps that check in one place; see Domain::requireId().
     */
    public function records(Domain|int $domain): BoundDomainRecords
    {
        return new BoundDomainRecords(
            new DomainRecords($this->connection, $this->logger),
            $this->zoneId($domain)
        );
    }

    /**
     * Take over a zone from another provider, by AXFR from its current nameserver.
     *
     * The transfer has to be permitted at the far end. When it is not, this is a 400 saying
     * so rather than an empty zone, which is the better failure - but it means the remote
     * side is where to look, not this call.
     */
    public function import(string $domain, string $remoteNameserver): Domain
    {
        $this->logger->info('Linode domain import', [
            'domain' => $domain,
            'remote_nameserver' => $remoteNameserver,
        ]);

        return Domain::fromArray($this->apiPost('domains/import', [
            'domain' => $domain,
            'remote_nameserver' => $remoteNameserver,
        ])->object());
    }

    /**
     * Copy a zone, records and all, under a new name.
     *
     * Named cloneTo() rather than clone() because `clone` is a language construct: PHP would
     * allow the method, and every reader would have to check whether the call was a method
     * or an operator.
     */
    public function cloneTo(Domain|int $id, string $newDomain): Domain
    {
        $this->logger->info('Linode domain clone', ['id' => $this->zoneId($id), 'domain' => $newDomain]);

        return Domain::fromArray($this->apiPost($this->path($id) . '/clone', [
            'domain' => $newDomain,
        ])->object());
    }

    /**
     * The zone as it is actually served, rendered by Linode - one string per line.
     *
     * The authoritative answer to "what did that change really do", and the thing to diff
     * before and after a bulk edit. It is generated, so it carries the SOA and the
     * nameservers that Linode adds and that no record in the API describes.
     *
     * @return list<string>
     */
    public function zoneFile(Domain|int $id): array
    {
        $response = $this->apiGet($this->path($id) . '/zone-file');
        $lines = [];

        foreach ($response->array('zone_file') as $line) {
            if (is_scalar($line)) {
                $lines[] = (string) $line;
            }
        }

        return $lines;
    }

    /**
     * A zone id from either form.
     *
     * WIDENED ON THE METHODS THAT ACT ON A ZONE YOU ALREADY HOLD, and deliberately not on
     * get() or find(). Those PRODUCE a Domain; passing one to them would be a round trip to
     * fetch what the caller is already holding, and a uniform surface is not worth inviting
     * that. The four that consume one - update(), delete(), zoneFile(), cloneTo() - are where
     * the flow actually lands.
     *
     * The friction this removes is in the type system rather than in the typing. findByName()
     * answers `?Domain` and `Domain::$id` is `?int` in its own right, because a zone built
     * locally has no id - so narrowing away the first null does NOT narrow away the second,
     * and a caller who has done the null check correctly still meets `expects int, int|null
     * given` at level 10. Measured with PHPStan over the three realistic flows, and reported
     * by the first consumer.
     */
    private function zoneId(Domain|int $domain): int
    {
        return $domain instanceof Domain ? $domain->requireId() : $domain;
    }

    private function path(Domain|int $id): string
    {
        return 'domains/' . $this->zoneId($id);
    }
}
