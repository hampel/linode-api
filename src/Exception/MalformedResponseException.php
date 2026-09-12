<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

use Hampel\Linode\Api\Result\ResponseMeta;
use Psr\Http\Message\ResponseInterface;

/**
 * A 2xx whose body is not the JSON object this API always sends.
 *
 * This is not pedantry about content types, it is the failure mode that matters most in a
 * client whose answers are mostly lists. A maintenance page, a proxy's error document, a
 * WAF challenge and a truncated response are all a 200 with something other than JSON in
 * it - and decoded permissively they become an empty array, which reaches the caller as
 * "this account has no domains". Deleting records against that answer is the accident this
 * type exists to prevent.
 *
 * It extends ApiException so an existing `catch (ApiException)` sees it, even though
 * nothing was rejected.
 */
final class MalformedResponseException extends ApiException
{
    public static function forResponse(
        string $method,
        string $uri,
        ResponseInterface $response,
        string $body,
    ): self {
        $excerpt = trim(substr($body, 0, 200));

        return new self(
            sprintf(
                'Linode answered %s %s with HTTP %d but the body is not JSON (Content-Type: %s)%s',
                $method,
                $uri,
                $response->getStatusCode(),
                $response->getHeaderLine('Content-Type') ?: 'none',
                $excerpt === '' ? '; the body was empty' : ': ' . $excerpt
            ),
            $response->getStatusCode(),
            [],
            $body,
            null,
            ResponseMeta::fromResponse($response)
        );
    }
}
