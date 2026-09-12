<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Entity;

use Hampel\Linode\Api\Support\Cast;

/**
 * What a restricted user may do with one particular object - one domain, one Linode.
 *
 * `permissions` is null when the user has no access to it at all, which is why this is not
 * an enum: null is a third state, and the one that matters most.
 */
final class Grant implements \JsonSerializable
{
    public const READ_ONLY = 'read_only';

    public const READ_WRITE = 'read_write';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $label = null,
        public readonly ?string $permissions = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::int($row['id'] ?? null) ?? 0,
            Cast::string($row['label'] ?? null),
            Cast::string($row['permissions'] ?? null),
            $row,
        );
    }

    public function canRead(): bool
    {
        return $this->permissions === self::READ_ONLY || $this->permissions === self::READ_WRITE;
    }

    public function canWrite(): bool
    {
        return $this->permissions === self::READ_WRITE;
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'label' => $this->label,
            'permissions' => $this->permissions,
        ];
    }
}
