<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Result;

use Hampel\Linode\Api\Entity\Profile;

/**
 * The answer to "does this token work, and what may it do" - from one request.
 *
 * `GET /v4/profile` is the cheapest call on the API and needs no OAuth scope, so it succeeds
 * for any credential that is valid at all. The scopes come back free in the response
 * headers, which is why this costs one request rather than two.
 *
 * REACHING THIS OBJECT MEANS THE TOKEN WORKS. A bad token does not produce a TokenStatus
 * saying so - it raises NotAuthenticatedException, because "the credential is wrong" is a
 * failure and returning a valid-looking object for it invites a caller who forgot to check a
 * boolean to carry on as though everything were fine.
 *
 *     try {
 *         $status = $linode->verify();
 *     } catch (NotAuthenticatedException) {
 *         // the token is missing, wrong, expired or revoked - Linode cannot tell you which
 *     }
 *
 *     $status->missing(['domains:read_write']);   // [] when the token can manage DNS
 */
final class TokenStatus implements \JsonSerializable
{
    public function __construct(
        public readonly Profile $profile,
        public readonly Scopes $scopes,
        public readonly ResponseMeta $meta,
    ) {
    }

    public function username(): string
    {
        return $this->profile->username;
    }

    /**
     * Whether the user's access is limited object by object. A restricted user's scopes are
     * only half the picture - the grants endpoint is the other half.
     */
    public function isRestricted(): bool
    {
        return $this->profile->restricted;
    }

    /**
     * Whether the token holds a scope, with read_write counting for read_only.
     */
    public function allows(string $scope): bool
    {
        return $this->scopes->allows($scope);
    }

    /**
     * Which of these scopes the token does NOT hold - the check to make at startup, so a
     * misconfigured credential is reported once with a useful message instead of as a 403 in
     * the middle of some later operation.
     *
     * @param  list<string>  $required
     * @return list<string>
     */
    public function missing(array $required): array
    {
        return $this->scopes->missing($required);
    }

    /**
     * Whether the scopes could be read at all.
     *
     * Linode reports `unknown` in `X-OAuth-Scopes` where it has no credential to describe,
     * and a proxy can strip the header. Either way the answer is "the question was not
     * answered" - which is not the same as "the token has no permissions", and must not be
     * treated as a reason to refuse to start.
     */
    public function scopesAreKnown(): bool
    {
        return !$this->scopes->isUnknown();
    }

    /**
     * One line naming the account and what it may do, for a startup log or a diagnostic
     * command. Carries no part of the token.
     */
    public function summary(): string
    {
        return sprintf(
            'Linode token for %s (uid %s), %s, scopes: %s',
            $this->profile->username === '' ? 'an unnamed user' : $this->profile->username,
            $this->profile->uid === null ? 'unknown' : (string) $this->profile->uid,
            $this->profile->restricted ? 'restricted' : 'unrestricted',
            (string) $this->scopes
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'username' => $this->profile->username,
            'uid' => $this->profile->uid,
            'email' => $this->profile->email,
            'restricted' => $this->profile->restricted,
            'scopes' => $this->scopes->jsonSerialize(),
            'meta' => $this->meta->toArray(),
        ];
    }
}
