<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Entity;

use Hampel\Linode\Api\Enum\CaaTag;
use Hampel\Linode\Api\Enum\RecordType;
use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Hampel\Linode\Api\Support\Cast;
use Hampel\Linode\Api\Support\Ttl;

/**
 * One record in a zone.
 *
 * WHICH FIELDS MEAN ANYTHING DEPENDS ENTIRELY ON THE TYPE, and Linode rejects a field that
 * does not belong to the type being created rather than ignoring it. That is why this class
 * has a named constructor per type instead of one constructor with eleven optional
 * arguments: the named one takes what the type actually needs, in the order it needs it, and
 * toArray() emits only the fields that type is allowed to send.
 *
 *     DomainRecord::a('www', '203.0.113.10')->withTtl(300);
 *     DomainRecord::mx('mail.example.com', priority: 10);
 *     DomainRecord::txt('_dmarc', 'v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com');
 *     DomainRecord::srv('sip', 'tcp', 'sip.example.com', port: 5060, priority: 10, weight: 5);
 *     DomainRecord::caa(CaaTag::Issue, 'letsencrypt.org');
 *
 * `name` IS RELATIVE TO THE ZONE, not an FQDN: `www` in the `example.com` zone is
 * `www.example.com`. An empty name is the zone apex, which is what an MX or a CAA for the
 * domain itself wants.
 */
final class DomainRecord implements \JsonSerializable
{
    /**
     * The fields Linode will filter or sort a record list on.
     *
     * @var list<string>
     */
    public const FILTERABLE = ['name', 'target', 'type', 'tag'];

    /**
     * Linode substitutes the requesting client's own IPv4 address for this target. Useful
     * for a dynamic-DNS updater, which is the only place it makes sense.
     */
    public const REMOTE_ADDR = '[remote_addr]';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly RecordType $type,
        public readonly ?string $name = null,
        public readonly ?string $target = null,
        public readonly ?int $id = null,
        public readonly ?int $ttlSec = null,
        public readonly ?int $priority = null,
        public readonly ?int $weight = null,
        public readonly ?int $port = null,
        public readonly ?string $service = null,
        public readonly ?string $protocol = null,
        public readonly ?CaaTag $tag = null,
        public readonly ?\DateTimeImmutable $created = null,
        public readonly ?\DateTimeImmutable $updated = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * An IPv4 address record. Pass DomainRecord::REMOTE_ADDR as the address to have Linode
     * fill in the caller's own IP.
     */
    public static function a(string $name, string $address): self
    {
        return new self(RecordType::A, self::name($name), self::required($address, 'An A record needs an address.'));
    }

    /**
     * An IPv6 address record.
     */
    public static function aaaa(string $name, string $address): self
    {
        return new self(RecordType::AAAA, self::name($name), self::required($address, 'An AAAA record needs an address.'));
    }

    /**
     * An alias. `name` has to be unique within the zone, and a CNAME cannot coexist with any
     * other record of the same name - that is DNS, not Linode.
     */
    public static function cname(string $name, string $target): self
    {
        return new self(RecordType::CNAME, self::name($name), self::required($target, 'A CNAME needs a target.'));
    }

    /**
     * A mail exchanger.
     *
     * The argument order is target first, because that is the part you always supply: `name`
     * is the mail subdomain and is empty for the ordinary case of mail addressed at the
     * domain itself.
     *
     * Lower priority wins.
     */
    public static function mx(string $target, int $priority = 0, string $name = ''): self
    {
        return new self(
            RecordType::MX,
            self::name($name),
            self::required($target, 'An MX record needs a mail server, or use nullMx() to refuse mail.'),
            priority: $priority,
        );
    }

    /**
     * RFC 7505: this domain accepts no mail.
     *
     * An empty target, an empty name and priority 0. Linode will not create one while any
     * other MX record exists on the zone, and will not create another MX record while one of
     * these does - so switching either way means deleting first.
     */
    public static function nullMx(): self
    {
        return new self(RecordType::MX, '', '', priority: 0);
    }

    /**
     * A name server for the zone, or for a subdomain of it.
     *
     * Wildcard NS records are not supported by Linode.
     */
    public static function ns(string $target, string $name = ''): self
    {
        return new self(RecordType::NS, self::name($name), self::required($target, 'An NS record needs a name server.'));
    }

    /**
     * A text record - SPF, DKIM, DMARC, a domain-verification token.
     */
    public static function txt(string $name, string $value): self
    {
        return new self(RecordType::TXT, self::name($name), self::required($value, 'A TXT record needs a value.'));
    }

    /**
     * A service record.
     *
     * LINODE BUILDS THE RECORD NAME ITSELF, from the service and the protocol, and `name` is
     * unused on an SRV - so pass `sip` and `tcp`, NOT `_sip._tcp`. The API prepends the
     * underscore to both and appends a period to the service. Passing the decorated form
     * produces `__sip.` and a record nothing will find, with no error to say so, which is why
     * this refuses a leading underscore rather than passing it through.
     *
     * Lower priority wins; among equal priorities, higher weight is preferred.
     */
    public static function srv(
        string $service,
        string $protocol,
        string $target,
        int $port,
        int $priority = 0,
        int $weight = 0,
    ): self {
        return new self(
            RecordType::SRV,
            null,
            self::required($target, 'An SRV record needs a target.'),
            port: $port,
            priority: $priority,
            weight: $weight,
            service: self::undecorated($service, 'service'),
            protocol: self::undecorated($protocol, 'protocol'),
        );
    }

    /**
     * A certificate authority authorisation.
     *
     * `name` is the subdomain the policy applies to; empty - the default - applies it to the
     * whole zone, which is nearly always what is wanted.
     */
    public static function caa(CaaTag $tag, string $target, string $name = ''): self
    {
        return new self(
            RecordType::CAA,
            self::name($name),
            self::required($target, 'A CAA record needs a target - an authority, or a URL for iodef.'),
            tag: $tag,
        );
    }

    /**
     * A pointer record.
     *
     * Reverse DNS for a Linode's IP address is NOT set here - it is set on the IP address
     * itself, through the networking endpoints, and a PTR in a forward zone will not do it.
     * Linode's rDNS guide is the reference.
     */
    public static function ptr(string $name, string $target): self
    {
        return new self(RecordType::PTR, self::name($name), self::required($target, 'A PTR record needs a target.'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            RecordType::tryFrom(Cast::string($row['type'] ?? null) ?? '') ?? RecordType::A,
            Cast::string($row['name'] ?? null),
            Cast::string($row['target'] ?? null),
            Cast::int($row['id'] ?? null),
            Cast::int($row['ttl_sec'] ?? null),
            Cast::int($row['priority'] ?? null),
            Cast::int($row['weight'] ?? null),
            Cast::int($row['port'] ?? null),
            Cast::string($row['service'] ?? null),
            Cast::string($row['protocol'] ?? null),
            CaaTag::tryFrom(Cast::string($row['tag'] ?? null) ?? ''),
            Cast::datetime($row['created'] ?? null),
            Cast::datetime($row['updated'] ?? null),
            $row,
        );
    }

    /**
     * The create payload: `type`, and only the fields that type is allowed to carry.
     *
     * The filtering is the point. Linode's field documentation says "only valid for SRV
     * record requests" of `service`, `protocol`, `port` and `weight`, and a record read back
     * from the API has all four present as nulls or zeroes - so echoing a fetched record
     * straight into a create would send four fields the type does not accept.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['type' => $this->type->value];

        // SRV is the one type with no name of its own: Linode composes it from the service
        // and the protocol, and sending one is at best ignored.
        if (!$this->type->usesServiceFields() && $this->name !== null) {
            $payload['name'] = $this->name;
        }

        if ($this->target !== null) {
            $payload['target'] = $this->target;
        }

        if ($this->ttlSec !== null) {
            $payload['ttl_sec'] = $this->ttlSec;
        }

        if ($this->type->usesPriority() && $this->priority !== null) {
            $payload['priority'] = $this->priority;
        }

        if ($this->type->usesServiceFields()) {
            foreach (['service' => $this->service, 'protocol' => $this->protocol] as $key => $value) {
                if ($value !== null) {
                    $payload[$key] = $value;
                }
            }

            foreach (['port' => $this->port, 'weight' => $this->weight] as $key => $value) {
                if ($value !== null) {
                    $payload[$key] = $value;
                }
            }
        }

        if ($this->type->usesTag() && $this->tag !== null) {
            $payload['tag'] = $this->tag->value;
        }

        return $payload;
    }

    /**
     * The same payload without `type`.
     *
     * A RECORD'S TYPE CANNOT BE CHANGED. Linode's update endpoint does not accept the field
     * at all - it is absent from the request schema - so changing an A record into a CNAME
     * means deleting and recreating it.
     *
     * @return array<string, mixed>
     */
    public function toUpdateArray(): array
    {
        $payload = $this->toArray();

        unset($payload['type']);

        return $payload;
    }

    public function withName(string $name): self
    {
        return $this->with(name: $name);
    }

    public function withTarget(string $target): self
    {
        return $this->with(target: $target);
    }

    /**
     * How long resolvers may cache this record.
     *
     * Linode rounds up to its own list of intervals - the same list a zone uses, despite the
     * documentation describing a different rule for records. See Ttl, which was corrected
     * from measurement. `effectiveTtl()` reports what a value will become.
     */
    public function withTtl(int $seconds): self
    {
        return $this->with(ttlSec: $seconds);
    }

    /**
     * Lower wins. Meaningful on MX and SRV; ignored elsewhere, and not sent elsewhere.
     */
    public function withPriority(int $priority): self
    {
        return $this->with(priority: $priority);
    }

    /**
     * Higher wins, among records of equal priority. SRV only.
     */
    public function withWeight(int $weight): self
    {
        return $this->with(weight: $weight);
    }

    /**
     * The TTL that will actually be stored, with Linode's silent rounding up applied.
     *
     * NULL WHEN THE TTL IS 0, and that is the honest answer rather than a missing feature. On
     * a record, 0 means "the default" and Linode's documentation does not say whose - the
     * fixed 86400 a zone's ttl_sec falls back to, or the zone's own TTL. Those differ by any
     * factor the zone likes, and a record does not know which zone it is in, so this cannot
     * answer it from here whichever turns out to be true.
     *
     * An earlier version returned 86400 for this case. That was a guess dressed as a fact,
     * from the same documentation that turned out to be wrong about the rounding rule twice
     * over, so it is now null and the caller decides.
     */
    public function effectiveTtl(): ?int
    {
        $ttl = $this->ttlSec ?? 0;

        return $ttl > 0 ? Ttl::round($ttl) : null;
    }

    /**
     * The record's name within the zone, as a fully qualified name.
     *
     * The API returns `name` relative to the zone and never tells a record which zone it is
     * in, so the zone has to be supplied. An empty name is the apex and answers as the zone
     * itself.
     */
    public function fqdn(string $zone): string
    {
        $zone = trim($zone, '. ');
        $name = trim((string) $this->name, '. ');

        return $name === '' ? $zone : $name . '.' . $zone;
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : $this->toArray();
    }

    private function with(
        ?string $name = null,
        ?string $target = null,
        ?int $ttlSec = null,
        ?int $priority = null,
        ?int $weight = null,
    ): self {
        return new self(
            $this->type,
            $name ?? $this->name,
            $target ?? $this->target,
            $this->id,
            $ttlSec ?? $this->ttlSec,
            $priority ?? $this->priority,
            $weight ?? $this->weight,
            $this->port,
            $this->service,
            $this->protocol,
            $this->tag,
            $this->created,
            $this->updated,
            $this->raw,
        );
    }

    /**
     * A name is trimmed but not otherwise touched, and an empty one is kept rather than
     * turned into null: on this API an empty name is a value - the zone apex - and null is
     * the absence of the field.
     */
    private static function name(string $name): string
    {
        return trim($name);
    }

    private static function required(string $value, string $message): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($message);
        }

        return trim($value);
    }

    /**
     * SRV's service and protocol are written undecorated, because Linode decorates them.
     */
    private static function undecorated(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('An SRV record needs a %s.', $field));
        }

        if (str_starts_with($value, '_')) {
            throw new InvalidArgumentException(sprintf(
                'Write the SRV %s undecorated - "%s", not "%s". Linode prepends the underscore '
                    . 'itself, so the decorated form becomes "_%s" and matches nothing.',
                $field,
                ltrim($value, '_'),
                $value,
                $value
            ));
        }

        return $value;
    }
}
