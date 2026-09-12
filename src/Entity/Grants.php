<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Entity;

use Hampel\Linode\Api\Support\Cast;

/**
 * What a restricted user is allowed to do: account-wide permissions, and a per-object grant
 * for every object they can see.
 *
 * AN UNRESTRICTED USER HAS NO GRANTS, AND SAYS SO WITH A 204. `GET /v4/profile/grants`
 * answers `204 No Content` when the user is unrestricted, not an object full of
 * `read_write`. Read as a normal response that is an empty grants object, which looks
 * exactly like a user who may do nothing - the opposite of the truth. The Profile endpoint
 * turns the 204 into null for that reason, and `Profile::$restricted` is what to branch on.
 *
 * So there are three answers to "may this token create a domain", not two:
 *
 *     $profile->restricted === false        // yes: unrestricted, no grants exist
 *     $grants?->canAddDomains() === true    // yes: restricted, and granted
 *     $grants?->canAddDomains() === false   // no
 */
final class Grants implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $global  the account-wide permissions - `add_domains`,
     *                                        `account_access` and the rest. Kept as an array
     *                                        because Linode adds a key to it whenever it
     *                                        adds a product, and a class would be behind
     * @param  array<string, list<Grant>>  $entities  per-object grants, keyed by object type
     *                                                as Linode names it: `domain`, `linode`,
     *                                                `volume`, `firewall` and so on
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly array $global = [],
        public readonly array $entities = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $global = [];

        foreach (Cast::array($row['global'] ?? null) as $key => $value) {
            if (is_string($key)) {
                $global[$key] = $value;
            }
        }

        $entities = [];

        foreach ($row as $key => $value) {
            if (!is_string($key) || $key === 'global' || !is_array($value)) {
                continue;
            }

            $grants = [];

            foreach ($value as $grant) {
                if (is_array($grant)) {
                    $fields = [];

                    foreach ($grant as $field => $item) {
                        if (is_string($field)) {
                            $fields[$field] = $item;
                        }
                    }

                    $grants[] = Grant::fromArray($fields);
                }
            }

            $entities[$key] = $grants;
        }

        return new self($global, $entities, $row);
    }

    /**
     * An account-wide permission by name, for one Linode has added since this release.
     */
    public function global(string $permission): mixed
    {
        return $this->global[$permission] ?? null;
    }

    /**
     * Whether the user may create new domains. This is account-wide and separate from the
     * per-domain grants: a user can be allowed to edit three zones and not to add a fourth.
     */
    public function canAddDomains(): bool
    {
        return Cast::bool($this->global['add_domains'] ?? null) === true;
    }

    /**
     * `read_only`, `read_write`, or null for no account-level access.
     */
    public function accountAccess(): ?string
    {
        return Cast::string($this->global['account_access'] ?? null);
    }

    /**
     * Every per-object grant of one type - `domain`, `linode`, `volume`.
     *
     * @return list<Grant>
     */
    public function for(string $type): array
    {
        return $this->entities[$type] ?? [];
    }

    /**
     * The grant for one specific object, or null when the user has none - which for a
     * restricted user means they cannot see it at all.
     */
    public function find(string $type, int $id): ?Grant
    {
        foreach ($this->for($type) as $grant) {
            if ($grant->id === $id) {
                return $grant;
            }
        }

        return null;
    }

    /**
     * Whether this user may change that zone. False for a domain they cannot see, and for
     * one they can only read.
     */
    public function canWriteDomain(int $domainId): bool
    {
        return $this->find('domain', $domainId)?->canWrite() === true;
    }

    public function canReadDomain(int $domainId): bool
    {
        return $this->find('domain', $domainId)?->canRead() === true;
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
