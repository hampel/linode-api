<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Result;

use Hampel\Linode\Api\Support\Cast;
use Psr\Http\Message\ResponseInterface;

/**
 * What came back beside the body: the rate limit, the token's scopes, and which build of
 * the API answered.
 *
 * Every one of these is on every response, which makes them cheap to act on. The rate limit
 * in particular: an integration that walks a large account can slow itself down from
 * `remaining` rather than waiting to be told with a 429.
 */
final class ResponseMeta implements \JsonSerializable
{
    private function __construct(
        /**
         * Requests allowed in the current window. Measured at 1840 on 12 September 2026 for
         * an unauthenticated request; it varies by endpoint, so read it rather than assuming
         * a figure.
         */
        public readonly ?int $rateLimit,
        public readonly ?int $rateLimitRemaining,
        /** A unix timestamp, not a duration. */
        public readonly ?int $rateLimitReset,
        /**
         * Present on EVERY response, including a 200 - measured, not assumed, and it varies:
         * 60 on a fresh window and 46 later in the same one, so it counts down to the reset
         * rather than being a fixed figure. Meaningful only alongside a 429; on its own it
         * says nothing.
         *
         * @see \Hampel\Linode\Api\Exception\ApiException
         */
        public readonly ?int $retryAfter,
        /** The scopes the credential holds, from `X-OAuth-Scopes`. */
        public readonly Scopes $scopes,
        /** What the endpoint would have accepted, from `X-Accepted-OAuth-Scopes`. */
        public readonly Scopes $acceptedScopes,
        /**
         * The API build that answered, from `X-Spec-Version`. Live on 12 September 2026:
         * 4.235.1, against a published specification at 4.215.0 - the deployed API runs
         * ahead of the document, which is worth knowing before concluding an endpoint does
         * not exist.
         */
        public readonly ?string $specVersion,
    ) {
    }

    /**
     * No metadata at all - for an exception built by hand rather than from a response.
     *
     * Every field reads as "not known" rather than as a value: the rate limit is null, and
     * the scopes report themselves unknown, which is the state that means "the question was
     * not answered" and not "no permissions". That distinction matters here more than
     * anywhere, because this is the value a caller gets when there was no response to read.
     */
    public static function none(): self
    {
        return new self(null, null, null, null, Scopes::fromHeader(''), Scopes::fromHeader(''), null);
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        return new self(
            self::header($response, 'X-RateLimit-Limit'),
            self::header($response, 'X-RateLimit-Remaining'),
            self::header($response, 'X-RateLimit-Reset'),
            self::header($response, 'Retry-After'),
            Scopes::fromHeader($response->getHeaderLine('X-OAuth-Scopes')),
            Scopes::fromHeader($response->getHeaderLine('X-Accepted-OAuth-Scopes')),
            $response->getHeaderLine('X-Spec-Version') ?: null,
        );
    }

    /**
     * When the current rate-limit window resets.
     */
    public function rateLimitResetsAt(): ?\DateTimeImmutable
    {
        if ($this->rateLimitReset === null || $this->rateLimitReset <= 0) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $this->rateLimitReset))->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Whether less than this proportion of the window is left - the check to make before
     * starting a long walk, rather than after being refused one.
     *
     * Unknown when the headers were not there, which reads as "not running out": a missing
     * header is not evidence of exhaustion, and treating it as such would stop a client that
     * is talking to a proxy which strips them.
     */
    public function isNearingRateLimit(float $fraction = 0.1): bool
    {
        if ($this->rateLimit === null || $this->rateLimitRemaining === null || $this->rateLimit <= 0) {
            return false;
        }

        return $this->rateLimitRemaining / $this->rateLimit < $fraction;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rate_limit' => $this->rateLimit,
            'rate_limit_remaining' => $this->rateLimitRemaining,
            'rate_limit_reset' => $this->rateLimitReset,
            'retry_after' => $this->retryAfter,
            'scopes' => (string) $this->scopes,
            'accepted_scopes' => (string) $this->acceptedScopes,
            'spec_version' => $this->specVersion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function header(ResponseInterface $response, string $name): ?int
    {
        return Cast::int($response->getHeaderLine($name));
    }
}
