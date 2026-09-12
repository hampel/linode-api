<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Support;

/**
 * The interval values Linode's DNS accepts, and what it does with one that is not on the
 * list.
 *
 * IT ROUNDS UP, SILENTLY. `ttl_sec`, `refresh_sec`, `retry_sec` and `expire_sec` on a zone,
 * and `ttl_sec` on a record, each take one of the values below; any other number is rounded
 * UP to the next one. Asking for 60 stores 120. The API returns 200, the stored value is not
 * the one you sent, and nothing says so.
 *
 * ONE RULE, NOT TWO - AND THE SPECIFICATION SAYS OTHERWISE. Linode's own documentation
 * describes a record's `ttl_sec` as rounded "to the NEAREST valid value" off a list that
 * starts at 300, which would be a different rule from the zone fields'. Measured against the
 * live API on 12 September 2026, by writing each value to a real record and reading back what
 * was stored:
 *
 *     asked      0    1   30   60  120  300  3000  86401  2419201
 *     stored     0   30   30  120  120  300  3600 172800  2419200
 *
 * That is round-up off the list below, identical to the zone rule, and it disagrees with the
 * documentation twice over: 30 and 120 are accepted for a record, and 900 stores 3600 rather
 * than the 300 that "nearest" would give. The zone rule was measured in the same run and does
 * match its documentation. So there is one rule here because the API has one, not because the
 * distinction was too fiddly to keep.
 *
 * Nothing in this package rewrites a caller's number - a client that quietly changes a value
 * is the same failure one layer further in. Use round() to find out what a value will become,
 * before writing it or to show the effective figure beside the requested one.
 *
 *     Ttl::round(60);        // 120
 *     Ttl::isValid(300);     // true
 *
 * ZERO IS NOT "NO CACHING". On a zone it means "use the default", and the default differs per
 * field: 86400 for ttl_sec, 14400 for refresh_sec and retry_sec, 1209600 for expire_sec. It
 * is also what every one of them reports until it has been set. On a RECORD, what zero
 * inherits has not been measured - see DomainRecord::effectiveTtl().
 */
final class Ttl
{
    /**
     * Every interval Linode stores, for a zone field and for a record alike.
     *
     * @var list<int>
     */
    public const VALUES = [
        0, 30, 120, 300, 3600, 7200, 14400, 28800, 57600, 86400,
        172800, 345600, 604800, 1209600, 2419200,
    ];

    /**
     * What a ZONE uses when the field is 0. A record's zero is a different question.
     */
    public const DEFAULT_TTL = 86400;

    public const DEFAULT_REFRESH = 14400;

    public const DEFAULT_RETRY = 14400;

    public const DEFAULT_EXPIRE = 1209600;

    public static function isValid(int $seconds): bool
    {
        return in_array($seconds, self::VALUES, true);
    }

    /**
     * The value Linode will actually store for this number.
     *
     * Anything above the largest accepted value comes back as that value - measured: 2419201
     * stores 2419200. There is nothing higher to round up to, and reporting the requested
     * number would be the one answer that is certainly wrong.
     */
    public static function round(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        foreach (self::VALUES as $value) {
            if ($value >= $seconds) {
                return $value;
            }
        }

        return self::VALUES[count(self::VALUES) - 1];
    }

    /**
     * The interval a ZONE field will behave as, resolving 0 to the default for that field.
     *
     * @param  'ttl_sec'|'refresh_sec'|'retry_sec'|'expire_sec'  $field
     */
    public static function effective(string $field, int $seconds): int
    {
        if ($seconds > 0) {
            return self::round($seconds);
        }

        return match ($field) {
            'refresh_sec' => self::DEFAULT_REFRESH,
            'retry_sec' => self::DEFAULT_RETRY,
            'expire_sec' => self::DEFAULT_EXPIRE,
            default => self::DEFAULT_TTL,
        };
    }
}
