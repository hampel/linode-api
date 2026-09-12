<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Support;

/**
 * The interval values Linode's DNS accepts, and what it does with one that is not on the
 * list.
 *
 * IT ROUNDS UP, SILENTLY. `ttl_sec`, `refresh_sec`, `retry_sec` and `expire_sec` each take
 * one of the values below, and any other number is rounded up to the next one - so asking
 * for 60 gets you 120, and asking for 86401 gets you 172800. The API returns 200, the
 * stored value is not the one you sent, and nothing says so.
 *
 * Nothing in this package rewrites a caller's number, because a client that quietly changes
 * a value is the same failure one layer further in. Use round() to find out what a value
 * will become - before writing it, or to show the effective figure beside the requested one.
 *
 *     Ttl::round(60);        // 120
 *     Ttl::isValid(300);     // true
 *
 * ZERO IS NOT "NO CACHING". It means "use the default", and the default differs per field:
 * 86400 for ttl_sec, 14400 for refresh_sec and retry_sec, 1209600 for expire_sec. It is also
 * what every one of them reports until it has been set.
 */
final class Ttl
{
    /**
     * @var list<int>
     */
    public const VALUES = [
        0, 30, 120, 300, 3600, 7200, 14400, 28800, 57600, 86400,
        172800, 345600, 604800, 1209600, 2419200,
    ];

    /**
     * A DOMAIN RECORD'S `ttl_sec` DOES NOT ACCEPT THE SAME VALUES AS A ZONE'S, and the
     * specification is explicit about both. A record's list starts at 300 - 30 and 120 are
     * not on it - and the rounding is described as to the NEAREST valid value rather than
     * up, which is the zone fields' wording. So 60 on a zone becomes 120, and 60 on a record
     * becomes 300.
     *
     * The difference is small and entirely invisible until a record's TTL is not what was
     * asked for, so the two lists are kept apart rather than merged into one that would be
     * right for one caller and wrong for the other.
     *
     * @var list<int>
     */
    public const RECORD_VALUES = [
        0, 300, 3600, 7200, 14400, 28800, 57600, 86400,
        172800, 345600, 604800, 1209600, 2419200,
    ];

    /**
     * What Linode uses when the field is 0.
     */
    public const DEFAULT_TTL = 86400;

    public const DEFAULT_REFRESH = 14400;

    public const DEFAULT_RETRY = 14400;

    public const DEFAULT_EXPIRE = 1209600;

    public static function isValid(int $seconds): bool
    {
        return in_array($seconds, self::VALUES, true);
    }

    public static function isValidForRecord(int $seconds): bool
    {
        return in_array($seconds, self::RECORD_VALUES, true);
    }

    /**
     * What a DOMAIN RECORD's ttl_sec will become. Rounds to the nearest accepted value, as
     * the specification describes for this field - not up, which is the zone fields' rule.
     * A tie goes to the larger value, cache being cheaper than queries.
     */
    public static function roundForRecord(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        $nearest = self::RECORD_VALUES[1];
        $distance = null;

        foreach (self::RECORD_VALUES as $value) {
            if ($value === 0) {
                continue;
            }

            $gap = abs($value - $seconds);

            if ($distance === null || $gap <= $distance) {
                $distance = $gap;
                $nearest = $value;
            }
        }

        return $nearest;
    }

    /**
     * The value Linode will actually store for this number.
     *
     * Anything above the largest accepted value comes back as that value: there is nothing
     * higher to round up to, and reporting the requested number would be the one answer that
     * is certainly wrong.
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
     * The interval this field will behave as, resolving 0 to the default for that field.
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
