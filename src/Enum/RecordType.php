<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Enum;

/**
 * The record types Linode's DNS manager supports.
 *
 * A CLOSED SET, AND THAT IS A PROMISE WITH A COST. An exhaustive `match` over this enum in
 * a consumer throws `UnhandledMatchError` the day a case is added, so adding one is a
 * breaking change for this package and gets a major version. Write a `default` arm anyway.
 *
 * Linode has added types before - CAA arrived after the others - and would presumably add
 * more if the need arose. What it does NOT support is as worth knowing as what it does:
 * there is no SSHFP, TLSA, NAPTR, DNSKEY or DS here, so a zone that uses one cannot be
 * hosted on Linode DNS whatever this package does.
 */
enum RecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';
    case NS = 'NS';
    case MX = 'MX';
    case CNAME = 'CNAME';
    case TXT = 'TXT';
    case SRV = 'SRV';
    case PTR = 'PTR';
    case CAA = 'CAA';

    /**
     * Whether this type uses `priority`. Linode accepts the field on others and ignores it.
     */
    public function usesPriority(): bool
    {
        return $this === self::MX || $this === self::SRV;
    }

    /**
     * Whether this type needs `service`, `protocol`, `port` and `weight` - which is SRV and
     * only SRV.
     */
    public function usesServiceFields(): bool
    {
        return $this === self::SRV;
    }

    /**
     * Whether this type uses `tag` - CAA and only CAA.
     */
    public function usesTag(): bool
    {
        return $this === self::CAA;
    }

    /**
     * An address record, where `target` is an IP rather than a name.
     */
    public function isAddress(): bool
    {
        return $this === self::A || $this === self::AAAA;
    }
}
