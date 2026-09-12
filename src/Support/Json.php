<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Support;

use Hampel\Linode\Api\Exception\InvalidArgumentException;

/**
 * Encoding a request body and decoding a response, with the two failure modes kept apart.
 *
 * Encoding fails because the CALLER handed us something that cannot be JSON - a resource
 * handle, a recursive structure, an invalid UTF-8 string - so it raises. Decoding fails
 * because whatever ANSWERED did not send JSON, which is not the caller's mistake and is
 * handled where the response is, so it returns null.
 */
final class Json
{
    /**
     * @param  array<mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException(
                'Could not encode the request payload as JSON: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @return array<mixed>|null  null when the body was not JSON, or was JSON but not an
     *                            object or array - which is what a maintenance page, a
     *                            proxy error document or a truncated response looks like
     */
    public static function decode(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
