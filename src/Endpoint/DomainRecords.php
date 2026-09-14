<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Endpoint;

use Hampel\Linode\Api\Entity\DomainRecord;
use Hampel\Linode\Api\Enum\RecordType;
use Hampel\Linode\Api\Result\Page;
use Hampel\Linode\Api\Support\Filter;

/**
 * The records inside a zone.
 *
 * https://techdocs.akamai.com/linode-api/reference/get-domain-records
 *
 * Every method takes the domain id first, because the API's paths do. Where several calls
 * concern one zone, `$linode->domains()->records($id)` binds it once and drops the argument.
 *
 * THE ZONE'S OWN SOA AND NS RECORDS ARE NOT HERE. Linode generates those and serves them
 * without representing them as records, so a zone that resolves perfectly well can answer
 * this endpoint with an empty list. `Domains::zoneFile()` is what shows the whole picture.
 *
 * A SLAVE ZONE'S RECORDS CANNOT BE WRITTEN. They arrive by transfer from the master, so
 * create, update and delete are refused on one.
 */
final class DomainRecords extends Endpoint
{
    /**
     * One page of a zone's records.
     *
     * @return Page<DomainRecord>
     */
    public function list(int $domainId, int $page = 1, ?Filter $filter = null, ?int $pageSize = null): Page
    {
        return $this->apiPaginate($this->path($domainId), DomainRecord::fromArray(...), $page, $filter, $pageSize);
    }

    /**
     * Every record in a zone, a page at a time.
     *
     * @return \Generator<int, DomainRecord>
     */
    public function each(int $domainId, ?Filter $filter = null, ?int $pageSize = null): \Generator
    {
        return $this->apiEach($this->path($domainId), DomainRecord::fromArray(...), $filter, $pageSize);
    }

    /**
     * Every record in a zone, as a list. A zone large enough for this to matter is rare, but
     * see Domains::all() for the caveat.
     *
     * @return list<DomainRecord>
     */
    public function all(int $domainId, ?Filter $filter = null): array
    {
        return iterator_to_array($this->each($domainId, $filter), false);
    }

    /**
     * Every record of one type.
     *
     * @return list<DomainRecord>
     */
    public function ofType(int $domainId, RecordType $type): array
    {
        return $this->all($domainId, Filter::where('type', $type->value));
    }

    /**
     * Every record with one name, of any type - which is how you find out what `www` already
     * is before adding to it. An empty name is the zone apex.
     *
     * @return list<DomainRecord>
     */
    public function named(int $domainId, string $name): array
    {
        return $this->all($domainId, Filter::where('name', trim($name)));
    }

    /**
     * One record. Raises NotFoundException when it is not there.
     */
    public function get(int $domainId, int $recordId): DomainRecord
    {
        return DomainRecord::fromArray($this->apiGet($this->path($domainId, $recordId))->object());
    }

    /**
     * One record, or null.
     */
    public function find(int $domainId, int $recordId): ?DomainRecord
    {
        return $this->apiFind($this->path($domainId, $recordId), DomainRecord::fromArray(...));
    }

    /**
     * Add a record.
     *
     *     $linode->records()->create($domainId, DomainRecord::a('www', '203.0.113.10'));
     *
     * NOTHING STOPS A DUPLICATE. Linode will happily hold two identical A records for the
     * same name, and DNS will serve both. Check with named() first where that matters.
     *
     * @param  DomainRecord|array<string, mixed>  $record
     */
    public function create(int $domainId, DomainRecord|array $record): DomainRecord
    {
        $payload = $record instanceof DomainRecord ? $record->toArray() : $record;

        return DomainRecord::fromArray($this->apiPost($this->path($domainId), $payload)->object());
    }

    /**
     * Change a record. Partial, like every update on this API.
     *
     * A RECORD'S TYPE CANNOT CHANGE. The field is not in the update schema at all, so
     * `type` is stripped from a DomainRecord passed here rather than sent and rejected -
     * see DomainRecord::toUpdateArray(). Turning an A into a CNAME means delete and create.
     *
     * @param  DomainRecord|array<string, mixed>  $changes
     */
    public function update(int $domainId, int $recordId, DomainRecord|array $changes): DomainRecord
    {
        $payload = $changes instanceof DomainRecord ? $changes->toUpdateArray() : $changes;

        unset($payload['type']);

        return DomainRecord::fromArray($this->apiPut($this->path($domainId, $recordId), $payload)->object());
    }

    /**
     * Remove a record.
     *
     * Immediate, and subject only to whatever TTL resolvers are still holding - which is the
     * argument for lowering a TTL well before a change rather than at the moment of it.
     */
    public function delete(int $domainId, int $recordId): void
    {

        $this->apiDelete($this->path($domainId, $recordId));
    }

    private function path(int $domainId, ?int $recordId = null): string
    {
        $path = 'domains/' . $domainId . '/records';

        return $recordId === null ? $path : $path . '/' . $recordId;
    }
}
