<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Support;

/**
 * Reading a value out of an API result without trusting it.
 *
 * Every one of these returns null rather than throwing when the value is absent or the
 * wrong shape. Linode's JSON is better typed than most - an integer field arrives as an
 * integer - but a field a restricted user may not see, a field added after this package was
 * written and a field removed from a beta endpoint all look the same from here, and a client
 * that treated any of them as an error would break on an account whose only difference was
 * its grants.
 */
final class Cast
{
    public static function string(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    public static function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-')))
            ? (int) $value
            : (is_float($value) ? (int) $value : null);
    }

    public static function float(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : null;
    }

    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match ($value) {
            1, '1' => true,
            0, '0' => false,
            default => null,
        };
    }

    /**
     * @return array<mixed>
     */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * A list of strings, with anything that is not one dropped.
     *
     * For `tags`, `master_ips`, `axfr_ips` and the OAuth scope list - all of which are
     * arrays of strings and none of which is worth a class.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    /**
     * A Linode timestamp, as a DateTimeImmutable in UTC.
     *
     * LINODE SENDS ITS DATES WITHOUT A TIMEZONE. Every `created`, `updated` and
     * `active_since` in the specification is documented as `format: date-time` with an
     * example of `2018-01-01T00:01:01` - no `Z`, no offset. The values are UTC; the string
     * does not say so.
     *
     * That matters because `new DateTimeImmutable('2018-01-01T00:01:01')` interprets an
     * unqualified string in PHP's OWN default timezone, so the same response read on a box
     * set to Australia/Sydney and a box set to UTC produces two different instants, ten or
     * eleven hours apart depending on the season. Nothing errors, and a comparison against
     * `now` quietly answers wrongly. So the timezone is supplied here rather than inferred,
     * and a value that DOES carry one is left alone - if Linode starts sending offsets, this
     * keeps working.
     */
    public static function datetime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        try {
            $parsed = new \DateTimeImmutable($value, $utc);
        } catch (\Exception) {
            return null;
        }

        return $parsed->setTimezone($utc);
    }
}
