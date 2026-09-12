<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Support;

use Hampel\Linode\Api\Exception\InvalidArgumentException;

/**
 * The `X-Filter` header: how Linode filters and sorts a collection.
 *
 * IT IS A HEADER, NOT A QUERY STRING, and that is the first thing that surprises people.
 * There is no `?domain=example.com` on this API. Filtering is a JSON object in a request
 * header, which is why it needs building rather than concatenating:
 *
 *     X-Filter: {"domain": "example.com"}
 *
 * A filter is immutable - every method returns a new one - so a base filter can be kept in
 * a property and specialised per call without the specialisations accumulating.
 *
 *     $filter = Filter::where('type', 'A')->orderBy('name');
 *
 *     foreach ($linode->records()->each($domainId, $filter) as $record) { ... }
 *
 * ONLY SOME FIELDS ARE FILTERABLE, and the API decides which. On a domain they are `domain`,
 * `group` and `tags`; on a domain record, `name`, `target`, `type` and `tag`. Naming
 * anything else is a 400 rather than an ignored condition, which is the better failure but
 * still a failure - so build filters from the constants on the entity classes where there
 * is one.
 *
 * `+order_by` has the same restriction and one more: it has to name a filterable field, and
 * `+order` does nothing without it.
 */
final class Filter implements \JsonSerializable
{
    /**
     * @param  list<array<string, mixed>>  $conditions  each one a single criterion object
     */
    private function __construct(
        private readonly array $conditions = [],
        private readonly ?string $orderBy = null,
        private readonly ?string $order = null,
    ) {
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * The common case, as a one-liner: `Filter::where('domain', 'example.com')`.
     */
    public static function where(string $field, string|int|float|bool $value): self
    {
        return self::make()->and($field, $value);
    }

    /**
     * At least one of these has to hold - Linode's `+or`.
     *
     * Each argument contributes its own conditions as one alternative, so a branch that is
     * itself several conditions is ANDed within the branch and ORed against the others:
     *
     *     Filter::anyOf(
     *         Filter::where('type', 'A'),
     *         Filter::where('type', 'AAAA'),
     *     );
     *
     * Ordering on a branch is ignored - `+order_by` is a property of the whole query, so set
     * it on the result rather than inside a branch.
     */
    public static function anyOf(self ...$filters): self
    {
        $branches = [];

        foreach ($filters as $filter) {
            $criteria = $filter->criteria();

            if ($criteria !== []) {
                $branches[] = $criteria;
            }
        }

        if ($branches === []) {
            return self::make();
        }

        return new self([['+or' => $branches]]);
    }

    /**
     * Equality. Named `and` because every condition on one filter is ANDed with the rest,
     * which is Linode's behaviour for an object with several keys and is what `+and` makes
     * explicit.
     */
    public function and(string $field, string|int|float|bool $value): self
    {
        return $this->with([self::field($field) => $value]);
    }

    /**
     * Not equal to - `+neq`.
     */
    public function not(string $field, string|int|float|bool $value): self
    {
        return $this->with([self::field($field) => ['+neq' => $value]]);
    }

    /**
     * The value contains this substring - `+contains`. A substring match, not a pattern:
     * there are no wildcards here.
     */
    public function contains(string $field, string $value): self
    {
        return $this->with([self::field($field) => ['+contains' => $value]]);
    }

    public function greaterThan(string $field, int|float $value): self
    {
        return $this->with([self::field($field) => ['+gt' => $value]]);
    }

    public function greaterOrEqual(string $field, int|float $value): self
    {
        return $this->with([self::field($field) => ['+gte' => $value]]);
    }

    public function lessThan(string $field, int|float $value): self
    {
        return $this->with([self::field($field) => ['+lt' => $value]]);
    }

    public function lessOrEqual(string $field, int|float $value): self
    {
        return $this->with([self::field($field) => ['+lte' => $value]]);
    }

    /**
     * Sort by a field. The field has to be one the API says is filterable, and ordering
     * without one of those is a 400.
     */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $direction = strtolower(trim($direction));

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidArgumentException(sprintf(
                'A filter can be ordered "asc" or "desc", not "%s".',
                $direction
            ));
        }

        return new self($this->conditions, self::field($field), $direction);
    }

    public function descending(): self
    {
        if ($this->orderBy === null) {
            throw new InvalidArgumentException(
                'A direction means nothing without a field to order by: call orderBy() first, '
                    . 'or pass the direction to it.'
            );
        }

        return new self($this->conditions, $this->orderBy, 'desc');
    }

    public function ascending(): self
    {
        if ($this->orderBy === null) {
            throw new InvalidArgumentException(
                'A direction means nothing without a field to order by: call orderBy() first, '
                    . 'or pass the direction to it.'
            );
        }

        return new self($this->conditions, $this->orderBy, 'asc');
    }

    /**
     * Nothing to send - no conditions and no ordering. The connection omits the header
     * entirely for one of these rather than sending `{}`.
     */
    public function isEmpty(): bool
    {
        return $this->conditions === [] && $this->orderBy === null;
    }

    /**
     * The filter as the object Linode expects, ordering included.
     *
     * ONE condition is written at the top level and SEVERAL are wrapped in `+and`. Both are
     * accepted - sibling keys in one object are ANDed implicitly - but the explicit form
     * cannot collide with itself, and two conditions on the same field are a real thing to
     * want: `+gte` and `+lte` on the same field is a range, and as sibling keys the second
     * would overwrite the first.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $filter = $this->criteria();

        if ($this->orderBy !== null) {
            $filter['+order_by'] = $this->orderBy;
            $filter['+order'] = $this->order ?? 'asc';
        }

        return $filter;
    }

    /**
     * The header value: the filter, encoded.
     */
    public function toHeader(): string
    {
        return Json::encode($this->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The conditions alone, without the ordering - which is what a `+or` branch contributes.
     *
     * @return array<string, mixed>
     */
    private function criteria(): array
    {
        if ($this->conditions === []) {
            return [];
        }

        if (count($this->conditions) === 1) {
            return $this->conditions[0];
        }

        return ['+and' => $this->conditions];
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function with(array $condition): self
    {
        return new self([...$this->conditions, $condition], $this->orderBy, $this->order);
    }

    /**
     * A field name that is empty, or that starts with `+`, is a mistake worth catching here:
     * the first produces a filter Linode cannot read, and the second is an operator being
     * passed where a field belongs, which reaches the API as a nonsense condition.
     */
    private static function field(string $field): string
    {
        $field = trim($field);

        if ($field === '') {
            throw new InvalidArgumentException('A filter condition needs a field name.');
        }

        if (str_starts_with($field, '+')) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is one of Linode\'s filter operators, not a field name. Use the method for it.',
                $field
            ));
        }

        return $field;
    }
}
