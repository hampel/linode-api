<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

use Hampel\Linode\Api\ApiError;
use Psr\Http\Message\ResponseInterface;

/**
 * Linode answered, and the answer was not a success.
 *
 * READ THE STATUS AND THE FIELD, NOT THE PROSE. Linode reports every caller mistake in the
 * same envelope - `{"errors": [{"field": "...", "reason": "..."}]}` - and the status is what
 * separates a rejected value (400) from a token that may not do this (403) from a token that
 * is not a token at all (401). The subclasses below are that separation, so a consumer
 * catches the one it can do something about rather than inspecting a message.
 */
abstract class ApiException extends LinodeException
{
    /**
     * @param  list<ApiError>  $errors  the API's own errors[], parsed
     * @param  string  $body  the raw response body, for when it was not JSON at all
     */
    final public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $errors = [],
        public readonly string $body = '',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * @param  array<mixed>|null  $decoded  the decoded body, or null if it was not JSON
     */
    public static function fromResponse(
        string $method,
        string $uri,
        ResponseInterface $response,
        ?array $decoded,
        string $body,
    ): self {
        $status = $response->getStatusCode();
        $errors = ApiError::listFrom($decoded);

        $detail = $errors !== []
            ? implode('; ', array_map(static fn (ApiError $error): string => $error->describe(), $errors))
            : trim($body);

        $message = sprintf(
            'Linode rejected %s %s (HTTP %d)%s',
            $method,
            $uri,
            $status,
            $detail === '' ? '' : ': ' . $detail
        );

        $retryAfter = self::retryAfter($response);

        return match (true) {
            $status === 400 => new ValidationException($message, $status, $errors, $body, $retryAfter),
            $status === 401 => new NotAuthenticatedException($message, $status, $errors, $body, $retryAfter),
            $status === 403 => new NotPermittedException($message, $status, $errors, $body, $retryAfter),
            $status === 404 => new NotFoundException($message, $status, $errors, $body, $retryAfter),
            $status === 429 => new TooManyRequestsException($message, $status, $errors, $body, $retryAfter),
            $status >= 500 => new ServerException($message, $status, $errors, $body, $retryAfter),
            default => new ClientException($message, $status, $errors, $body, $retryAfter),
        };
    }

    /**
     * Every error's reason, in order, for a caller that wants to show them rather than
     * branch on them.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_map(static fn (ApiError $error): string => $error->reason, $this->errors);
    }

    /**
     * The errors that named a field, keyed by it - which is the shape a form wants.
     *
     * An error with no `field` is not in here. Linode omits the key when the problem is not
     * about one element of the request, and those are exactly the ones with nowhere to go on
     * a form.
     *
     * @return array<string, list<string>>
     */
    public function fieldErrors(): array
    {
        $fields = [];

        foreach ($this->errors as $error) {
            if ($error->field !== null) {
                $fields[$error->field][] = $error->reason;
            }
        }

        return $fields;
    }

    /**
     * Whether any error named this field.
     */
    public function concerns(string $field): bool
    {
        foreach ($this->errors as $error) {
            if ($error->field === $field) {
                return true;
            }
        }

        return false;
    }

    /**
     * LINODE SENDS `Retry-After: 60` ON EVERY RESPONSE, INCLUDING A 200.
     *
     * Measured against the live API on 12 September 2026: a plain `GET /v4/regions` that
     * succeeded carried `Retry-After: 60` beside `X-RateLimit-Remaining: 1839`. So the
     * header's presence says nothing whatsoever about whether you were throttled, and a
     * client that backs off because it saw one would sleep after every successful call.
     *
     * It is captured anyway, because on a 429 it IS the answer - and the way to tell the
     * two apart is the exception's own type, not this value.
     */
    private static function retryAfter(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');

        return ctype_digit($header) ? (int) $header : null;
    }
}
