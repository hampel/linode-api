<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Result;

use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Hampel\Linode\Api\Support\Cast;

/**
 * One page of a collection, and the pagination that came with it.
 *
 * Every paginated endpoint on this API answers in the same envelope, so this works for one
 * this package has never wrapped:
 *
 *     {"data": [...], "page": 1, "pages": 3, "results": 247}
 *
 * Note `pages` is the LAST page number and `results` is the total across all of them - two
 * numbers that are easy to read as each other.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * The API's own default, applied when a request does not ask for a size.
     */
    public const DEFAULT_SIZE = 100;

    /**
     * LINODE REFUSES A PAGE SMALLER THAN 25. Measured on 12 September 2026:
     * `?page_size=1` answers `400 {"errors": [{"field": "page_size", "reason": "Must be
     * 25-500"}]}`, which is a surprise to anyone who has asked another API for one item to
     * see the shape of it. Use `results` off any page for a count instead - a `page_size=25`
     * request already carries it.
     */
    public const MIN_SIZE = 25;

    public const MAX_SIZE = 500;

    /**
     * @param  list<T>  $items
     * @param  int  $lastPage  Linode's `pages`
     * @param  int  $total  Linode's `results`, across every page
     */
    public function __construct(
        public readonly array $items,
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly int $total,
    ) {
    }

    /**
     * @template TItem
     * @param  array<mixed>  $data  the decoded response body
     * @param  callable(array<string, mixed>): TItem  $map  how to build one item
     * @return self<TItem>
     */
    public static function fromResponse(array $data, callable $map): self
    {
        $items = [];
        $raw = $data['data'] ?? [];

        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_array($item)) {
                    $row = [];

                    foreach ($item as $key => $value) {
                        if (is_string($key)) {
                            $row[$key] = $value;
                        }
                    }

                    $items[] = $map($row);
                }
            }
        }

        return new self(
            $items,
            Cast::int($data['page'] ?? null) ?? 1,
            Cast::int($data['pages'] ?? null) ?? 1,
            Cast::int($data['results'] ?? null) ?? count($items),
        );
    }

    /**
     * Whether another page exists.
     *
     * This is the test to loop on. Reading until a page comes back empty works here - unlike
     * some APIs, a page past the last one is an empty `data` and a 200, not an error - but it
     * costs one wasted request every time, on an API that counts them.
     */
    public function hasMore(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * How many items are on THIS page. `$page->total` is how many there are altogether.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return list<T>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }

    /**
     * Refuse a page size the API will refuse, before spending a request finding out.
     */
    public static function assertValidPageSize(int $pageSize): void
    {
        if ($pageSize < self::MIN_SIZE || $pageSize > self::MAX_SIZE) {
            throw new InvalidArgumentException(sprintf(
                'Linode accepts a page size between %d and %d; %d was asked for. The API '
                    . 'rejects anything outside that range with a 400, including a page of 1.',
                self::MIN_SIZE,
                self::MAX_SIZE,
                $pageSize
            ));
        }
    }
}
