<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Entity;

use Hampel\Linode\Api\Support\Cast;

/**
 * The billing account behind the token.
 *
 * READING THIS NEEDS `account:read_only`, which the profile does not - so a token that can
 * manage every zone on the account may still be refused here, with a 403. That is a
 * deliberate separation on Linode's part and a good one: an integration that manages DNS has
 * no business reading a credit card. Check a token with the profile, not with this.
 *
 * The financial fields are strings rather than floats. They arrive as JSON numbers, and
 * `balance` is currency: putting it through a binary float to hand it to a template is how
 * a figure ends up one cent out. `balanceCents()` is there for arithmetic.
 */
final class Account implements \JsonSerializable
{
    /**
     * @param  list<string>  $capabilities  what the account is entitled to use - `Linodes`,
     *                                      `Object Storage`, `Managed Databases` and so on
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $email = null,
        public readonly ?string $company = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $euuid = null,
        public readonly ?float $balance = null,
        public readonly ?float $balanceUninvoiced = null,
        public readonly ?string $billingSource = null,
        public readonly ?string $country = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $zip = null,
        public readonly ?\DateTimeImmutable $activeSince = null,
        public readonly array $capabilities = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['email'] ?? null),
            Cast::string($row['company'] ?? null),
            Cast::string($row['first_name'] ?? null),
            Cast::string($row['last_name'] ?? null),
            Cast::string($row['euuid'] ?? null),
            Cast::float($row['balance'] ?? null),
            Cast::float($row['balance_uninvoiced'] ?? null),
            Cast::string($row['billing_source'] ?? null),
            Cast::string($row['country'] ?? null),
            Cast::string($row['city'] ?? null),
            Cast::string($row['state'] ?? null),
            Cast::string($row['zip'] ?? null),
            Cast::datetime($row['active_since'] ?? null),
            Cast::strings($row['capabilities'] ?? null),
            $row,
        );
    }

    /**
     * The account holder's name, or the company where there is one.
     */
    public function name(): string
    {
        if ($this->company !== null && trim($this->company) !== '') {
            return $this->company;
        }

        return trim(implode(' ', array_filter([$this->firstName, $this->lastName])));
    }

    /**
     * Whether the account is entitled to a product. The strings are Linode's own, exactly as
     * they appear in `capabilities` - "Object Storage", not "object_storage".
     */
    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * The balance in whole cents, for arithmetic that must not drift.
     */
    public function balanceCents(): ?int
    {
        return $this->balance === null ? null : (int) round($this->balance * 100);
    }

    /**
     * Whether anything is owed. A negative balance is a credit.
     */
    public function owesMoney(): bool
    {
        return ($this->balance ?? 0.0) > 0.0;
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
