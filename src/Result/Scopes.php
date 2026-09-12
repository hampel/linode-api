<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Result;

/**
 * What a token is allowed to do, as reported by the `X-OAuth-Scopes` response header.
 *
 * Linode sends this on EVERY response, success or failure, so the answer to "what may this
 * credential do" comes back free with the first call rather than needing one of its own.
 *
 * A scope is `<area>:<access>` - `domains:read_write`, `account:read_only` - and `*` means
 * everything, which is what an unrestricted personal access token created with full access
 * reports.
 *
 * READ-WRITE IMPLIES READ-ONLY. `domains:read_write` satisfies a `domains:read_only`
 * requirement, and allows() knows that; a string comparison against the header would not.
 *
 * WHAT IS MEASURED AND WHAT IS NOT. The header's presence and its `unknown` value on an
 * unauthenticated request were measured against the live API on 12 September 2026. The
 * SEPARATOR between multiple scopes was not - that needs a real token, and none was
 * available when this was written. So parsing accepts commas, whitespace or both, which
 * covers every form the header could plausibly take, and the `verify` harness exercise
 * prints the raw header so the first run against a real token settles it.
 */
final class Scopes implements \JsonSerializable, \Stringable
{
    /**
     * What Linode sends for a request that carried no usable credential. Not a scope, and
     * not an empty list either - so it is recognised rather than parsed as one.
     */
    public const UNKNOWN = 'unknown';

    public const ALL = '*';

    /**
     * @param  list<string>  $scopes
     */
    private function __construct(
        public readonly array $scopes,
        public readonly string $raw,
    ) {
    }

    public static function fromHeader(string $header): self
    {
        $header = trim($header);

        if ($header === '' || strtolower($header) === self::UNKNOWN) {
            return new self([], $header);
        }

        $parts = preg_split('/[\s,]+/', $header) ?: [];
        $scopes = [];

        foreach ($parts as $part) {
            $part = strtolower(trim($part));

            if ($part !== '' && !in_array($part, $scopes, true)) {
                $scopes[] = $part;
            }
        }

        return new self($scopes, $header);
    }

    /**
     * Whether the token holds every scope, which `*` is how Linode says.
     */
    public function isUnrestricted(): bool
    {
        return in_array(self::ALL, $this->scopes, true);
    }

    /**
     * Whether the header said nothing usable - no credential, or a credential the API did
     * not recognise. An empty list is not the same as "no permissions": it means the
     * question was not answered.
     */
    public function isUnknown(): bool
    {
        return $this->scopes === [];
    }

    /**
     * Whether this token satisfies a requirement.
     *
     *     $scopes->allows('domains:read_only')   // true when the token has read_write too
     *
     * A bare area name asks whether the token has any access to it at all:
     *
     *     $scopes->allows('domains')             // read_only or read_write will do
     */
    public function allows(string $scope): bool
    {
        $scope = strtolower(trim($scope));

        if ($scope === '' || $this->isUnknown()) {
            return false;
        }

        if ($this->isUnrestricted() || in_array($scope, $this->scopes, true)) {
            return true;
        }

        [$area, $access] = self::split($scope);

        foreach ($this->scopes as $held) {
            [$heldArea, $heldAccess] = self::split($held);

            if ($heldArea !== $area) {
                continue;
            }

            // No access asked for means "any". Otherwise read_write covers read_only, and
            // nothing covers read_write but itself.
            if ($access === null || $access === $heldAccess || $heldAccess === 'read_write') {
                return true;
            }
        }

        return false;
    }

    /**
     * Which of these the token does NOT hold - the shape a check wants, because the useful
     * message names what is missing rather than restating what was asked for.
     *
     * @param  list<string>  $required
     * @return list<string>
     */
    public function missing(array $required): array
    {
        $missing = [];

        foreach ($required as $scope) {
            if (!$this->allows($scope)) {
                $missing[] = $scope;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public function jsonSerialize(): array
    {
        return $this->scopes;
    }

    public function __toString(): string
    {
        return $this->scopes === [] ? self::UNKNOWN : implode(' ', $this->scopes);
    }

    /**
     * @return array{string, string|null}
     */
    private static function split(string $scope): array
    {
        $colon = strpos($scope, ':');

        return $colon === false
            ? [$scope, null]
            : [substr($scope, 0, $colon), substr($scope, $colon + 1)];
    }
}
