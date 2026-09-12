<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

use Hampel\Linode\Api\ApiError;
use Hampel\Linode\Api\Result\ResponseMeta;
use Psr\Http\Message\ResponseInterface;

/**
 * Linode answered, and the answer was not a success.
 *
 * Linode reports every caller mistake in the same envelope -
 * `{"errors": [{"field": "...", "reason": "..."}]}` - so the subclasses below are what let a
 * consumer catch the one thing it can act on rather than inspecting a message.
 *
 * THE STATUS IS NOT ENOUGH TO BUILD THAT SEPARATION FROM, and this is the one place this
 * package deliberately departs from it. Measured on 12 September 2026: a valid token calling
 * an endpoint its scopes do not cover answers **401**, not 403 -
 *
 *     401  X-OAuth-Scopes: domains:read_write   "Your OAuth token is not authorized to use
 *          X-Accepted-OAuth-Scopes: account:read_only     this endpoint."
 *
 * - which is the same status as a token that is not a token at all:
 *
 *     401  X-OAuth-Scopes: unknown              "Invalid Token"
 *
 * Those two need opposite responses from an operator: widen the token's scopes, against
 * replace the credential entirely. Mapping both to one type would leave a consumer matching
 * on the reason string, which is exactly what this hierarchy exists to avoid.
 *
 * `X-OAuth-Scopes` separates them, and does it on a fact rather than on prose: Linode can
 * only report a token's scopes for a token it recognises. So a 401 that names them is a
 * scope failure - NotPermittedException - and a 401 that says `unknown` is a bad credential.
 * A stripped header degrades to the second, which is the conservative reading.
 */
abstract class ApiException extends LinodeException
{
    /**
     * What came back beside the body: the token's scopes, what the endpoint would have
     * accepted, and the rate limit.
     *
     * Never null - an exception built by hand without a response carries ResponseMeta::none(),
     * whose scopes report themselves unknown. That keeps every reader of this property on one
     * path instead of two, and the "we could not tell" case is expressed in the value rather
     * than in its absence.
     */
    public readonly ResponseMeta $meta;

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
        ?ResponseMeta $meta = null,
    ) {
        parent::__construct($message, $statusCode);

        $this->meta = $meta ?? ResponseMeta::none();
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
        $meta = ResponseMeta::fromResponse($response);

        $detail = $errors !== []
            ? implode('; ', array_map(static fn (ApiError $error): string => $error->describe(), $errors))
            : trim($body);

        // A 401 whose X-OAuth-Scopes names the token's own scopes is a scope failure, not a
        // bad credential - see the class docblock. Both are 401; only this tells them apart.
        $isScopeFailure = $status === 401 && !$meta->scopes->isUnknown();

        $message = sprintf(
            'Linode rejected %s %s (HTTP %d)%s',
            $method,
            $uri,
            $status,
            $detail === '' ? '' : ': ' . $detail
        );

        if ($isScopeFailure) {
            // The status says "who are you" and the truth is "not with that scope", so the
            // message says which, and names the gap. Both halves come off the response, so
            // the operator is told what to change without going to look it up.
            $message .= sprintf(
                '. The token is valid - this is a scope failure, despite the 401. It holds [%s]'
                    . ' and the endpoint wants [%s].',
                (string) $meta->scopes,
                (string) $meta->acceptedScopes
            );
        }

        $retryAfter = self::retryAfter($response);

        return match (true) {
            $status === 400 => new ValidationException($message, $status, $errors, $body, $retryAfter, $meta),
            $isScopeFailure => new NotPermittedException($message, $status, $errors, $body, $retryAfter, $meta),
            $status === 401 => new NotAuthenticatedException($message, $status, $errors, $body, $retryAfter, $meta),
            $status === 403 => new NotPermittedException($message, $status, $errors, $body, $retryAfter, $meta),
            $status === 404 => new NotFoundException($message, $status, $errors, $body, $retryAfter, $meta),
            $status === 429 => new TooManyRequestsException($message, $status, $errors, $body, $retryAfter, $meta),
            $status >= 500 => new ServerException($message, $status, $errors, $body, $retryAfter, $meta),
            default => new ClientException($message, $status, $errors, $body, $retryAfter, $meta),
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
     * LINODE SENDS A `Retry-After` ON EVERY RESPONSE, INCLUDING A 200.
     *
     * Measured against the live API on 12 September 2026: a successful `GET /v4/regions`
     * carried `Retry-After: 60` beside `X-RateLimit-Remaining: 1839`, and a later successful
     * call carried 46. It counts down to the rate-limit window's reset rather than being a
     * fixed figure, and it is there whether or not anything went wrong.
     *
     * So the header's presence says nothing about whether you were throttled, and a client
     * that backs off because it saw one would sleep after every successful call. It is
     * captured anyway, because on a 429 it IS the answer - and the way to tell the two apart
     * is the exception's own type, not this value.
     */
    private static function retryAfter(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');

        return ctype_digit($header) ? (int) $header : null;
    }
}
