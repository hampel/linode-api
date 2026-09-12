<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Result;

/**
 * A successful API response: the decoded body, and what came with it.
 *
 * Returned rather than a bare array so the rate limit and the token's scopes are reachable
 * without the client keeping the last response in a mutable field. Endpoint classes read
 * `->data`; an integration pacing itself against the rate limit reads `->meta`.
 */
final class ApiResponse implements \JsonSerializable
{
    /**
     * @param  array<mixed>  $data  the decoded JSON body. `[]` for the empty object Linode
     *                              answers a successful DELETE with, and for a 204
     */
    public function __construct(
        public readonly array $data,
        public readonly int $status,
        public readonly ResponseMeta $meta,
    ) {
    }

    /**
     * Whether the body carried this key at all, as distinct from carrying it as null.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * One top-level key, or the default when it is absent or null.
     */
    public function value(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * One top-level key that is expected to be an object or a list.
     *
     * @return array<mixed>
     */
    public function array(string $key): array
    {
        $value = $this->data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * The whole body as an array of string-keyed values - what an entity's fromArray()
     * takes, for the endpoints that answer with the object itself rather than wrapping it.
     *
     * Linode does that for every single-object read: `GET /v4/domains/1234` answers with the
     * domain, not with `{"domain": {...}}`.
     *
     * @return array<string, mixed>
     */
    public function object(): array
    {
        $object = [];

        foreach ($this->data as $key => $value) {
            if (is_string($key)) {
                $object[$key] = $value;
            }
        }

        return $object;
    }

    /**
     * Whether the response carried nothing - which for this API is what success looks like
     * on a DELETE. Linode answers those with `{}` and a 200 rather than a 204.
     */
    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
