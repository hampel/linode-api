<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Entity;

use Hampel\Linode\Api\Enum\DomainStatus;
use Hampel\Linode\Api\Enum\DomainType;
use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Hampel\Linode\Api\Exception\RuntimeException;
use Hampel\Linode\Api\Support\Cast;
use Hampel\Linode\Api\Support\Ttl;

/**
 * A DNS zone hosted by Linode.
 *
 * The same class reads and writes. Linode's create body is a subset of what a read returns,
 * so a second "new domain" class would duplicate thirteen fields to omit one, and the
 * round trip - read a domain, change one thing, send it back - would need a conversion
 * nobody would remember to keep in step.
 *
 * Build one with the named constructor for its type and refine it:
 *
 *     Domain::master('example.com', 'hostmaster@example.com')
 *         ->withTtl(300)
 *         ->withTags(['production']);
 *
 * OPTIONAL FIELDS ARE NULL UNTIL SET, and only the ones that are not null are sent. That is
 * what makes an update partial: `$domain->withTtl(300)` on a domain built from scratch sends
 * one field, and Linode leaves the rest of the zone alone. A domain READ from the API has
 * every field populated, so sending that one back sends all of them - which is the same
 * values it already had, but it is worth knowing which of the two you are holding.
 */
final class Domain implements \JsonSerializable
{
    /**
     * The fields Linode will filter or sort a domain list on. Anything else in an `X-Filter`
     * is a 400, not an ignored condition.
     *
     * @var list<string>
     */
    public const FILTERABLE = ['domain', 'group', 'tags'];

    /**
     * @param  list<string>  $masterIps  required for a slave zone, meaningless on a master
     * @param  list<string>  $axfrIps  who may transfer this zone out of Linode. Empty unless
     *                                 you deliberately want otherwise
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $raw  the payload this was built from, so a field added
     *                                     to the API after this release is still reachable
     */
    public function __construct(
        public readonly string $domain,
        public readonly ?DomainType $type,
        public readonly ?int $id = null,
        public readonly ?string $soaEmail = null,
        public readonly ?DomainStatus $status = null,
        public readonly ?string $description = null,
        public readonly ?int $refreshSec = null,
        public readonly ?int $retrySec = null,
        public readonly ?int $expireSec = null,
        public readonly ?int $ttlSec = null,
        public readonly array $masterIps = [],
        public readonly array $axfrIps = [],
        public readonly array $tags = [],
        public readonly array $raw = [],
    ) {
        if (trim($domain) === '') {
            throw new InvalidArgumentException('A domain needs a name.');
        }
    }

    /**
     * A zone Linode is authoritative for.
     *
     * `soaEmail` is required by the API for a master zone and is not optional here either -
     * omitting it is a 400 that costs a round trip to discover.
     */
    public static function master(string $domain, string $soaEmail): self
    {
        if (trim($soaEmail) === '') {
            throw new InvalidArgumentException(
                'A master domain needs an SOA email address; Linode rejects one without.'
            );
        }

        return new self($domain, DomainType::Master, soaEmail: $soaEmail);
    }

    /**
     * A read-only copy of a zone held elsewhere, transferred in from `masterIps`.
     *
     * The records of a slave zone are not writable through the API - they come from the
     * transfer - so the record endpoints will refuse to create one on a domain of this type.
     *
     * @param  list<string>  $masterIps
     */
    public static function slave(string $domain, array $masterIps): self
    {
        if ($masterIps === []) {
            throw new InvalidArgumentException(
                'A slave domain needs at least one master IP to transfer the zone from.'
            );
        }

        return new self($domain, DomainType::Slave, masterIps: array_values($masterIps));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $domain = Cast::string($row['domain'] ?? null) ?? '';
        // Null for a type DomainType does not model, never a guess - reading an unknown one as
        // `Master`, as this did, would have claimed Linode is authoritative for a zone it may not be.
        $type = DomainType::tryFrom(Cast::string($row['type'] ?? null) ?? '');

        return new self(
            $domain,
            $type,
            Cast::int($row['id'] ?? null),
            Cast::string($row['soa_email'] ?? null),
            DomainStatus::tryFrom(Cast::string($row['status'] ?? null) ?? ''),
            Cast::string($row['description'] ?? null),
            Cast::int($row['refresh_sec'] ?? null),
            Cast::int($row['retry_sec'] ?? null),
            Cast::int($row['expire_sec'] ?? null),
            Cast::int($row['ttl_sec'] ?? null),
            Cast::strings($row['master_ips'] ?? null),
            Cast::strings($row['axfr_ips'] ?? null),
            Cast::strings($row['tags'] ?? null),
            $row,
        );
    }

    /**
     * The payload to send, with everything that was never set left out.
     *
     * `id` is never in it: it is read-only, it is in the URL on an update, and Linode
     * rejects a create that carries one.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'domain' => $this->domain,
            // Omitted for an unmodelled type rather than guessed: an update is partial, so
            // leaving it out leaves the zone's type alone.
            'type' => $this->type?->value,
            'soa_email' => $this->soaEmail,
            'status' => $this->status?->value,
            'description' => $this->description,
            'refresh_sec' => $this->refreshSec,
            'retry_sec' => $this->retrySec,
            'expire_sec' => $this->expireSec,
            'ttl_sec' => $this->ttlSec,
        ];

        // An empty array is a meaningful value for these three - it is how you REMOVE every
        // AXFR host or every tag - so they are included whenever they are set, and "set" for
        // a list means the property, not its emptiness. A domain built by fromArray() always
        // has all three; one built by a named constructor has whichever were passed.
        foreach (['master_ips' => $this->masterIps, 'axfr_ips' => $this->axfrIps, 'tags' => $this->tags] as $key => $list) {
            if ($list !== [] || array_key_exists($key, $this->raw)) {
                $payload[$key] = $list;
            }
        }

        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Every wither goes through here, and it is written out in full rather than reflecting
     * over the properties: `readonly` cannot be reassigned on a clone before PHP 8.5, so a
     * new instance is the only way, and building it from an array of changes would trade
     * fifteen typed arguments for fifteen `mixed` ones that static analysis cannot check.
     *
     * Passing nothing keeps the current value. There is no way to set a field back to null
     * through this, which is deliberate: on a partial update, null and absent are the same
     * thing to Linode, so "unset it" is not an operation the API offers - a zero is how you
     * return an interval to its default, and an empty array is how you clear a list.
     *
     * @param  list<string>|null  $masterIps
     * @param  list<string>|null  $axfrIps
     * @param  list<string>|null  $tags
     */
    private function with(
        ?string $soaEmail = null,
        ?DomainStatus $status = null,
        ?string $description = null,
        ?int $refreshSec = null,
        ?int $retrySec = null,
        ?int $expireSec = null,
        ?int $ttlSec = null,
        ?array $masterIps = null,
        ?array $axfrIps = null,
        ?array $tags = null,
    ): self {
        return new self(
            $this->domain,
            $this->type,
            $this->id,
            $soaEmail ?? $this->soaEmail,
            $status ?? $this->status,
            $description ?? $this->description,
            $refreshSec ?? $this->refreshSec,
            $retrySec ?? $this->retrySec,
            $expireSec ?? $this->expireSec,
            $ttlSec ?? $this->ttlSec,
            $masterIps ?? $this->masterIps,
            $axfrIps ?? $this->axfrIps,
            $tags ?? $this->tags,
            $this->raw,
        );
    }

    public function withSoaEmail(string $soaEmail): self
    {
        return $this->with(soaEmail: $soaEmail);
    }

    public function withDescription(string $description): self
    {
        return $this->with(description: $description);
    }

    /**
     * The zone's four intervals, in seconds. Pass only the ones you are changing.
     *
     * LINODE ROUNDS EACH OF THESE UP to its own list of accepted values, so 60 is stored as
     * 120 with no error - see Ttl, and effectiveTtl() below for what a value will become.
     * Zero is not "no caching": it means "use the default", which differs per field.
     */
    public function withIntervals(
        ?int $ttl = null,
        ?int $refresh = null,
        ?int $retry = null,
        ?int $expire = null,
    ): self {
        return $this->with(
            refreshSec: $refresh,
            retrySec: $retry,
            expireSec: $expire,
            ttlSec: $ttl,
        );
    }

    /**
     * How long resolvers may cache this zone's records. Shorthand for withIntervals().
     */
    public function withTtl(int $seconds): self
    {
        return $this->with(ttlSec: $seconds);
    }

    /**
     * Where a slave zone is transferred from. Meaningless on a master.
     *
     * @param  list<string>  $ips
     */
    public function withMasterIps(array $ips): self
    {
        return $this->with(masterIps: array_values($ips));
    }

    /**
     * Who may transfer this zone OUT of Linode.
     *
     * Linode's own note on this field calls it potentially dangerous and says to leave it
     * empty unless you mean it, which is worth repeating here: a zone transfer hands the
     * entire contents of the zone to whoever is listed. `[]` is both the default and how you
     * take an entry away.
     *
     * @param  list<string>  $ips
     */
    public function withAxfrIps(array $ips): self
    {
        return $this->with(axfrIps: array_values($ips));
    }

    /**
     * @param  list<string>  $tags
     */
    public function withTags(array $tags): self
    {
        return $this->with(tags: array_values($tags));
    }

    public function withStatus(DomainStatus $status): self
    {
        return $this->with(status: $status);
    }

    /**
     * Stop serving the zone without deleting it. Every record stays, so it is the reversible
     * way to take a zone out of service.
     */
    public function disabled(): self
    {
        return $this->withStatus(DomainStatus::Disabled);
    }

    public function active(): self
    {
        return $this->withStatus(DomainStatus::Active);
    }

    /**
     * The zone's id, insisting there is one.
     *
     * `$id` is null on a domain built locally for a create - it has not been given one yet -
     * so every call that needs an id has to deal with a nullable int. This is where that
     * happens once, with a message that says what went wrong, rather than at each call site
     * as a TypeError naming an argument position.
     */
    public function requireId(): int
    {
        if ($this->id === null) {
            throw new RuntimeException(sprintf(
                'This %s has no id: it was built here rather than read from the API, so it does '
                    . 'not exist at Linode yet. Create it first, and use the domain that comes back.',
                self::class
            ));
        }

        return $this->id;
    }

    public function isMaster(): bool
    {
        return $this->type === DomainType::Master;
    }

    public function isActive(): bool
    {
        return $this->status === DomainStatus::Active;
    }

    /**
     * The TTL that will actually apply, with Linode's rounding and its zero-means-default
     * rule resolved.
     */
    public function effectiveTtl(): int
    {
        return Ttl::effective('ttl_sec', $this->ttlSec ?? 0);
    }

    /**
     * What the API sent, unchanged - so a field added after this release is still reachable
     * without waiting for one.
     *
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : $this->toArray();
    }
}
