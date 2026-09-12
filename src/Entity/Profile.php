<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Entity;

use Hampel\Linode\Api\Support\Cast;

/**
 * The user the token belongs to.
 *
 * `GET /v4/profile` is the cheapest question this API answers and the one worth asking
 * first: it needs no OAuth scope at all, so it succeeds for any credential that is valid and
 * 401s for one that is not. That makes it the token check - see the Profile endpoint's
 * verify().
 *
 * `restricted` IS THE FIELD THAT MATTERS. An unrestricted user has no grants and can do
 * anything the token's scopes allow; a restricted one is limited further, per object, and
 * `GET /v4/profile/grants` is where that limit is written down.
 */
final class Profile implements \JsonSerializable
{
    /**
     * @param  list<string>  $authorizedKeys
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $username,
        public readonly ?string $email = null,
        public readonly ?int $uid = null,
        public readonly bool $restricted = false,
        public readonly bool $twoFactorAuth = false,
        public readonly ?string $timezone = null,
        public readonly ?string $authenticationType = null,
        public readonly bool $ipWhitelistEnabled = false,
        public readonly ?string $verifiedPhoneNumber = null,
        public readonly array $authorizedKeys = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['username'] ?? null) ?? '',
            Cast::string($row['email'] ?? null),
            Cast::int($row['uid'] ?? null),
            Cast::bool($row['restricted'] ?? null) ?? false,
            Cast::bool($row['two_factor_auth'] ?? null) ?? false,
            Cast::string($row['timezone'] ?? null),
            Cast::string($row['authentication_type'] ?? null),
            Cast::bool($row['ip_whitelist_enabled'] ?? null) ?? false,
            Cast::string($row['verified_phone_number'] ?? null),
            Cast::strings($row['authorized_keys'] ?? null),
            $row,
        );
    }

    /**
     * Whether this user's access is limited per object, in which case the grants endpoint
     * says how.
     */
    public function isRestricted(): bool
    {
        return $this->restricted;
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
