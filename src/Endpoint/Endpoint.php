<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Endpoint;

use Hampel\Linode\Api\Connection;
use Hampel\Linode\Api\Exception\NotFoundException;
use Hampel\Linode\Api\Result\ApiResponse;
use Hampel\Linode\Api\Result\Page;
use Hampel\Linode\Api\Support\Filter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The base class for everything that groups a set of endpoints - this package's own, and
 * anybody else's.
 *
 * EXTENDING THE API. Linode's v4 API has some three hundred paths and this package wraps the
 * dozen that manage DNS and identify a token. The rest are not out of reach: an Endpoint
 * subclass is a first-class citizen, and Client::endpoint() will construct one.
 *
 *     final class Instances extends Endpoint
 *     {
 *         public function all(): \Generator
 *         {
 *             return $this->apiEach('linode/instances', static fn (array $row) => $row);
 *         }
 *     }
 *
 *     $linode->endpoint(Instances::class)->all();
 *
 * There is nothing to register, nothing to boot and no container. The class IS the
 * registration, so a third-party package ships one, a consumer type-hints it, and static
 * analysis follows the return type all the way through.
 *
 * What subclassing buys over calling Connection directly is the pagination below. Every
 * collection on this API answers in the same `{data, page, pages, results}` envelope, so
 * apiPaginate() and apiEach() work for an endpoint this package has never heard of.
 */
abstract class Endpoint
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Every helper here carries an `api` prefix, which looks redundant inside a class whose
     * whole job is the API and is not. An endpoint group wants to call its own methods
     * get(), create() and delete() - those are the natural names - and PHP will not let a
     * subclass redeclare an inherited method with a different signature. Prefixing the
     * inherited ones leaves the good names free, for this package's endpoints and for
     * anybody else's.
     *
     * @param  array<string, scalar|null>  $query
     */
    protected function apiGet(string $path, array $query = [], ?Filter $filter = null): ApiResponse
    {
        return $this->connection->get($path, $query, $filter);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    protected function apiPost(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->connection->post($path, $payload, $query);
    }

    /**
     * Every update on this API is a PUT and every one of them is partial - see
     * Connection::put().
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    protected function apiPut(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->connection->put($path, $payload, $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    protected function apiDelete(string $path, array $query = []): ApiResponse
    {
        return $this->connection->delete($path, $query);
    }

    /**
     * A lookup where "no such thing" is an ordinary answer rather than a failure.
     *
     * Only a 404 becomes null. A 401 or a 403 is still raised, because a credential that
     * cannot see a zone and a zone that does not exist are different problems and reporting
     * the first as the second sends whoever reads it looking in the wrong place - which is
     * the specific confusion this package exists to avoid.
     *
     * @template TItem
     * @param  array<string, scalar|null>  $query
     * @param  callable(array<string, mixed>): TItem  $map
     * @return TItem|null
     */
    protected function apiFind(string $path, callable $map, array $query = [], ?Filter $filter = null): mixed
    {
        $response = $this->apiFindResponse($path, $query, $filter);

        if ($response === null) {
            return null;
        }

        $object = $response->object();

        return $object === [] ? null : $map($object);
    }

    /**
     * The same lookup, handing back the whole response rather than a mapped object - for an
     * endpoint whose answer is an envelope rather than the object itself.
     *
     * @param  array<string, scalar|null>  $query
     */
    protected function apiFindResponse(string $path, array $query = [], ?Filter $filter = null): ?ApiResponse
    {
        try {
            return $this->apiGet($path, $query, $filter);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * One page of a collection.
     *
     * @template TItem
     * @param  callable(array<string, mixed>): TItem  $map
     * @param  array<string, scalar|null>  $query
     * @return Page<TItem>
     */
    protected function apiPaginate(
        string $path,
        callable $map,
        int $page = 1,
        ?Filter $filter = null,
        ?int $pageSize = null,
        array $query = [],
    ): Page {
        $pageSize ??= $this->connection->config()->pageSize;

        if ($pageSize !== null) {
            Page::assertValidPageSize($pageSize);
            $query['page_size'] = $pageSize;
        }

        $query['page'] = max(1, $page);

        return Page::fromResponse($this->apiGet($path, $query, $filter)->data, $map);
    }

    /**
     * Every item across every page, fetched a page at a time and only as far as it is
     * consumed - so stopping early stops making requests.
     *
     * Terminating on hasMore() rather than on an empty page saves one request per walk. A
     * page past the end IS an empty page here, not the error some APIs answer with, so the
     * looser loop would also work; it would just spend a request on every walk to learn
     * something the previous response already said.
     *
     * A WALK IS A SAMPLE, NOT A SNAPSHOT. Each page is its own request against a collection
     * that can change between them, so a record created while a walk is in progress may
     * appear twice or not at all. Ordering explicitly with Filter::orderBy() narrows that -
     * an unordered collection has no promise of stability across pages at all - and does not
     * remove it. Where completeness matters, de-duplicate by id.
     *
     * @template TItem
     * @param  callable(array<string, mixed>): TItem  $map
     * @param  array<string, scalar|null>  $query
     * @return \Generator<int, TItem>
     */
    protected function apiEach(
        string $path,
        callable $map,
        ?Filter $filter = null,
        ?int $pageSize = null,
        array $query = [],
    ): \Generator {
        $page = 1;

        while (true) {
            $result = $this->apiPaginate($path, $map, $page, $filter, $pageSize, $query);

            yield from $result->items;

            if (!$result->hasMore() || $result->isEmpty()) {
                return;
            }

            $page++;
        }
    }
}
