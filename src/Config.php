<?php

declare(strict_types=1);

namespace Hampel\Linode\Api;

use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Hampel\Linode\Api\Result\Page;

/**
 * Which API we are talking to, and how its URLs are built.
 *
 * Unlike a self-hosted API there is exactly one Linode, so this is constructible with no
 * arguments at all and usually should be:
 *
 *     new Config()                          // https://api.linode.com/v4
 *     new Config(Config::VERSION_BETA)      // https://api.linode.com/v4beta
 *
 * The base URI is settable for the two cases that need it - a recorded fixture served
 * locally, and an outbound proxy that terminates the connection - and for nothing else.
 */
final class Config
{
    public const DEFAULT_HOST = 'api.linode.com';

    /**
     * The stable API. Everything this package wraps is here.
     */
    public const VERSION_STABLE = 'v4';

    /**
     * The same API with the endpoints that are still in beta added.
     *
     * It is a URL SEGMENT, not a header or a flag, so switching to it moves every request -
     * including the ones that were never in beta. That is Linode's design and it is worth
     * knowing before pointing a whole application at it: a beta endpoint is reached by
     * building a second client, not by asking the first for one call.
     */
    public const VERSION_BETA = 'v4beta';

    public readonly string $baseUri;

    /**
     * @param  string  $version  `v4` or `v4beta` - the specification declares these two and
     *                           nothing else, so anything else is refused here rather than
     *                           404ing on every call
     * @param  string|null  $baseUri  the API root WITHOUT the version segment, e.g.
     *                                `https://api.linode.com`. Null uses Linode's own host
     * @param  int|null  $pageSize  how many items a list request asks for when the caller
     *                              does not say. Null uses the API's own default of 100
     */
    public function __construct(
        public readonly string $version = self::VERSION_STABLE,
        ?string $baseUri = null,
        public readonly ?int $pageSize = null,
    ) {
        if ($version !== self::VERSION_STABLE && $version !== self::VERSION_BETA) {
            throw new InvalidArgumentException(sprintf(
                'The Linode API version must be "%s" or "%s", not "%s".',
                self::VERSION_STABLE,
                self::VERSION_BETA,
                $version
            ));
        }

        if ($pageSize !== null) {
            Page::assertValidPageSize($pageSize);
        }

        $baseUri = trim($baseUri ?? 'https://' . self::DEFAULT_HOST);

        if ($baseUri === '') {
            throw new InvalidArgumentException('The Linode API base URI cannot be empty.');
        }

        if (!str_starts_with($baseUri, 'http://') && !str_starts_with($baseUri, 'https://')) {
            throw new InvalidArgumentException(sprintf(
                'The Linode API base URI must be absolute, with a scheme: "%s" is not.',
                $baseUri
            ));
        }

        $this->baseUri = rtrim($baseUri, '/');
    }

    /**
     * Turn a path into an absolute URI.
     *
     * Three shapes arrive here. A relative path - `domains/1234/records` - is the usual one.
     * An absolute URI passes through untouched, so a URL the API itself produced can be fed
     * straight back in. And a path that already carries the version segment is normalised
     * rather than doubled, which is what makes `/v4/domains` copied out of the documentation
     * work as readily as `domains`.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function resolve(string $path, array $query = []): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $uri = $path;
        } else {
            $path = ltrim($path, '/');

            foreach ([self::VERSION_STABLE, self::VERSION_BETA] as $version) {
                if ($path === $version) {
                    $path = '';
                    break;
                }

                if (str_starts_with($path, $version . '/')) {
                    $path = substr($path, strlen($version) + 1);
                    break;
                }
            }

            $uri = rtrim($this->baseUri . '/' . $this->version . '/' . $path, '/');
        }

        $query = array_filter($query, static fn (mixed $value): bool => $value !== null);

        if ($query !== []) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $uri;
    }

    /**
     * The host the client will talk to, for anything that needs to name the endpoint - a log
     * line, an exception message, a settings screen.
     */
    public function host(): string
    {
        return parse_url($this->baseUri, PHP_URL_HOST) ?: self::DEFAULT_HOST;
    }

    /**
     * Whether this client is pointed at the beta API.
     */
    public function isBeta(): bool
    {
        return $this->version === self::VERSION_BETA;
    }
}
