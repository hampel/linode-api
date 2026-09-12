<?php

declare(strict_types=1);

namespace Hampel\Linode\Api;

/**
 * One entry from Linode's `errors` array.
 *
 * Every failure this API reports uses the same envelope, whatever the status:
 *
 *     {"errors": [{"field": "page_size", "reason": "Must be 25-500"}]}
 *
 * `reason` is always there. `field` is present when the problem is about one element of the
 * request and absent when it is not - `{"reason": "Invalid Token"}` names nothing, because
 * there is nothing in the body to name.
 *
 * Kept as an object rather than the raw array so `field` being absent and `field` being
 * null are the same thing here, which is what a caller means by "did it name a field".
 */
final class ApiError implements \JsonSerializable
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $field = null,
    ) {
    }

    /**
     * Pull the whole errors array out of a decoded body.
     *
     * Defensive about every level, because this runs on the failure path: the thing that
     * answered may not have been Linode at all, and an exception raised while building an
     * exception loses the original failure.
     *
     * @param  array<mixed>|null  $decoded
     * @return list<self>
     */
    public static function listFrom(?array $decoded): array
    {
        $errors = $decoded['errors'] ?? null;

        if (!is_array($errors)) {
            return [];
        }

        $parsed = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $reason = $error['reason'] ?? null;
            $field = $error['field'] ?? null;

            if (!is_scalar($reason) || (string) $reason === '') {
                continue;
            }

            $parsed[] = new self(
                (string) $reason,
                is_scalar($field) && (string) $field !== '' ? (string) $field : null,
            );
        }

        return $parsed;
    }

    /**
     * The error as one line, for a message a human reads.
     */
    public function describe(): string
    {
        return $this->field === null ? $this->reason : $this->field . ': ' . $this->reason;
    }

    /**
     * @return array{reason: string, field: string|null}
     */
    public function jsonSerialize(): array
    {
        return ['reason' => $this->reason, 'field' => $this->field];
    }
}
