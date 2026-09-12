<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * The request never got an answer: DNS, TLS, a timeout, a refused connection.
 *
 * Distinct from every other exception here, all of which mean Linode replied and the reply
 * was not a success. A retry is reasonable for this one and frequently is not for the
 * others.
 */
final class RequestException extends LinodeException
{
    public static function for(string $method, string $uri, ClientExceptionInterface $previous): self
    {
        return new self(
            sprintf('Could not reach the Linode API for %s %s: %s', $method, $uri, $previous->getMessage()),
            0,
            $previous
        );
    }
}
