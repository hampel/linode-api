<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Authentication;

use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * A bearer token - which on this API means both kinds of credential.
 *
 * LINODE DOES NOT DISTINGUISH A PERSONAL ACCESS TOKEN FROM AN OAUTH TOKEN on the wire.
 * Both go in the same header, both carry OAuth scopes, and both report those scopes back in
 * `X-OAuth-Scopes`. So there is one class rather than two that differ only in their name,
 * and where the difference does matter - a PAT never expires unless it was given an expiry,
 * an OAuth token always does - that is a property of the token's lifecycle rather than of
 * how it is presented.
 *
 * The token is not printable from here: __toString(), var_dump() and a stack trace all show
 * the description rather than the value. That is deliberate and is the main thing this class
 * does beyond setting a header - a credential in a transcript is a credential that has to be
 * rotated.
 */
final class AccessToken implements Authentication
{
    private readonly string $token;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        $token = trim($token);

        if ($token === '') {
            throw new InvalidArgumentException('A Linode API token is required.');
        }

        $this->token = $token;
    }

    public function applyTo(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', 'Bearer ' . $this->token);
    }

    /**
     * The token's length and its last four characters, which is enough to tell two
     * credentials apart in a log without being enough to use either.
     *
     * Four characters of a 64-character token is the same trade every dashboard makes. A
     * token short enough that four characters would be a meaningful fraction of it is not a
     * real Linode token, but the guard is here rather than assumed.
     */
    public function describe(): string
    {
        $length = strlen($this->token);

        return $length > 12
            ? sprintf('a Linode API token ending %s (%d characters)', substr($this->token, -4), $length)
            : sprintf('a Linode API token of %d characters', $length);
    }

    public function __toString(): string
    {
        return $this->describe();
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => $this->describe()];
    }
}
