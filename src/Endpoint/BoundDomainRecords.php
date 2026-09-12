<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Endpoint;

use Hampel\Linode\Api\Entity\DomainRecord;
use Hampel\Linode\Api\Enum\RecordType;
use Hampel\Linode\Api\Result\Page;
use Hampel\Linode\Api\Support\Filter;

/**
 * DomainRecords with the domain already supplied.
 *
 *     $records = $linode->domains()->records($domainId);
 *
 *     $records->all();
 *     $records->create(DomainRecord::a('www', '203.0.113.10'));
 *
 * Not an Endpoint subclass: it holds no connection and makes no requests of its own. It is a
 * partial application of the endpoint above, which is worth its file for one reason - a
 * sequence of calls against one zone should not repeat the id, and an id repeated by hand is
 * an id that can be wrong on the fourth line.
 */
final class BoundDomainRecords
{
    public function __construct(
        private readonly DomainRecords $records,
        public readonly int $domainId,
    ) {
    }

    /**
     * The unbound endpoint, for anything not forwarded here.
     */
    public function endpoint(): DomainRecords
    {
        return $this->records;
    }

    /**
     * @return Page<DomainRecord>
     */
    public function list(int $page = 1, ?Filter $filter = null, ?int $pageSize = null): Page
    {
        return $this->records->list($this->domainId, $page, $filter, $pageSize);
    }

    /**
     * @return \Generator<int, DomainRecord>
     */
    public function each(?Filter $filter = null, ?int $pageSize = null): \Generator
    {
        return $this->records->each($this->domainId, $filter, $pageSize);
    }

    /**
     * @return list<DomainRecord>
     */
    public function all(?Filter $filter = null): array
    {
        return $this->records->all($this->domainId, $filter);
    }

    /**
     * @return list<DomainRecord>
     */
    public function ofType(RecordType $type): array
    {
        return $this->records->ofType($this->domainId, $type);
    }

    /**
     * @return list<DomainRecord>
     */
    public function named(string $name): array
    {
        return $this->records->named($this->domainId, $name);
    }

    public function get(int $recordId): DomainRecord
    {
        return $this->records->get($this->domainId, $recordId);
    }

    public function find(int $recordId): ?DomainRecord
    {
        return $this->records->find($this->domainId, $recordId);
    }

    /**
     * @param  DomainRecord|array<string, mixed>  $record
     */
    public function create(DomainRecord|array $record): DomainRecord
    {
        return $this->records->create($this->domainId, $record);
    }

    /**
     * @param  DomainRecord|array<string, mixed>  $changes
     */
    public function update(int $recordId, DomainRecord|array $changes): DomainRecord
    {
        return $this->records->update($this->domainId, $recordId, $changes);
    }

    public function delete(int $recordId): void
    {
        $this->records->delete($this->domainId, $recordId);
    }
}
