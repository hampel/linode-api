<?php

declare(strict_types=1);

namespace Hampel\Linode\Api;

use Hampel\Linode\Api\Authentication\Authentication;
use Hampel\Linode\Api\Exception\ApiException;
use Hampel\Linode\Api\Exception\MalformedResponseException;
use Hampel\Linode\Api\Exception\RequestException;
use Hampel\Linode\Api\Result\ApiResponse;
use Hampel\Linode\Api\Result\ResponseMeta;
use Hampel\Linode\Api\Support\Filter;
use Hampel\Linode\Api\Support\Json;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Everything that touches HTTP, in one place.
 *
 * The client is injected as a PSR-18 ClientInterface rather than a concrete one, which is
 * the whole point of the package: a host application with its own HTTP stack - a
 * proxy-aware, SSRF-guarded client that all outbound requests are required to go through -
 * implements sendRequest() over it and shares this code, instead of writing a second API
 * client because ours hard-coded the wrong library. It is also what lets a Laravel
 * integration route this traffic through `Http::fake()`.
 *
 * A PSR-18 client does not throw on an HTTP status, only on a transport failure, so the two
 * failure modes stay cleanly separated here.
 *
 * This class is the extension point of last resort. Any of the three hundred endpoints this
 * package does not wrap can be called through it directly:
 *
 *     $linode->connection()->get('linode/instances')->array('data');
 */
final class Connection
{
    /**
     * Written without a charset parameter, and that is worth a note because it looks like an
     * omission.
     *
     * Linode itself does not care - it parses the body as JSON either way. The consumer's
     * test suite does. `Illuminate\Http\Client\Request::isJson()` is a substring test and
     * survives a parameter, but the same class's `isForm()` is an exact match, and a package
     * that writes its content types loosely in one place tends to write them loosely in
     * both. The narrow form is also what every recorded fixture of this API contains, so a
     * consumer matching on the header sees what they recorded.
     */
    public const JSON_CONTENT_TYPE = 'application/json';

    public function __construct(
        private readonly Config $config,
        private readonly Authentication $authentication,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function authentication(): Authentication
    {
        return $this->authentication;
    }

    /**
     * The injected transport, exposed rather than hidden because a caller assembling
     * something this class does not cover needs the same client and the same factories to do
     * it, rather than reaching for an HTTP library of its own.
     */
    public function client(): ClientInterface
    {
        return $this->client;
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory;
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  Filter|null  $filter  sent as the `X-Filter` header. Filtering on this API is
     *                               a header rather than a query parameter - see Filter
     */
    public function get(string $path, array $query = [], ?Filter $filter = null): ApiResponse
    {
        return $this->send($this->request('GET', $path, $query, $filter));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    public function post(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->send($this->withJson($this->request('POST', $path, $query), $payload));
    }

    /**
     * Every update on this API is a PUT, and it is a PARTIAL update despite that.
     *
     * Linode's PUT endpoints apply the fields they are given and leave the rest alone, which
     * is PATCH behaviour under a PUT verb. So sending `{"ttl_sec": 300}` to a record changes
     * the TTL and does not blank the target - and a client that helpfully sent the whole
     * object back would be doing unnecessary work rather than necessary work.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    public function put(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->send($this->withJson($this->request('PUT', $path, $query), $payload));
    }

    /**
     * Linode answers a successful delete with `{}` and a 200, not a 204 and not an empty body -
     * measured on 2026-09-13 as `Content-Length: 2`. So a delete decodes like any other
     * response, and an empty body on this path would be an error rather than a success.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function delete(string $path, array $query = []): ApiResponse
    {
        return $this->send($this->request('DELETE', $path, $query));
    }

    /**
     * Build a request without sending it, for a caller assembling something this class does
     * not cover. The credential, the Accept header and any filter are already applied.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function request(string $method, string $path, array $query = [], ?Filter $filter = null): RequestInterface
    {
        $request = $this->requestFactory
            ->createRequest($method, $this->config->resolve($path, $query))
            ->withHeader('Accept', self::JSON_CONTENT_TYPE);

        if ($filter !== null && !$filter->isEmpty()) {
            $request = $request->withHeader('X-Filter', $filter->toHeader());
        }

        return $this->authentication->applyTo($request);
    }

    /**
     * Attach a JSON body to a request built elsewhere.
     *
     * @param  array<string, mixed>  $payload
     */
    public function withJson(RequestInterface $request, array $payload): RequestInterface
    {
        return $request
            ->withHeader('Content-Type', self::JSON_CONTENT_TYPE)
            ->withBody($this->streamFactory->createStream(Json::encode($payload)));
    }

    /**
     * Send a request that was built elsewhere, with this connection's error handling.
     */
    public function send(RequestInterface $request): ApiResponse
    {
        $response = $this->dispatch($request);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = Json::decode($body);
        $meta = ResponseMeta::fromResponse($response);

        if ($status >= 200 && $status < 300) {
            $this->noteRateLimit($request, $meta);

            if ($decoded !== null) {
                return new ApiResponse($decoded, $status, $meta);
            }

            // 204 IS THE ONLY SUCCESS WITH A LEGITIMATELY EMPTY BODY, and it is narrowed to
            // exactly that rather than accepting any empty-bodied 2xx.
            //
            // The temptation is to treat an empty 200 as an empty answer too, and this did
            // until it was measured. A successful DELETE looks like the case that needs it and
            // is not: `DELETE domains/{id}/records/{id}` answers 200 with `Content-Length: 2`
            // and a body of `{}` - measured against the live API on 2026-09-13 - which decodes
            // above and never reaches here. `GET profile/grants` on an unrestricted user is
            // the 204, and nothing else on this API is known to answer 2xx with nothing in it.
            //
            // What the wider version masked was a consumer's test rather than a real response.
            // Laravel's `Http::fake()` with no arguments answers EVERY request with an empty
            // 200, so a fake with a forgotten body read as "this account has no zones" and the
            // assertions passed. That is the exact failure this whole branch exists to prevent,
            // reached from the one direction nobody looks at. Reported by the first consumer.
            if ($status === 204) {
                return new ApiResponse([], $status, $meta);
            }

            // A 2xx that did not decode is not an empty answer, it is somebody else's
            // answer - a maintenance page, a proxy error document, a truncated body, a test
            // fake with no body. Read as [] it would reach the caller as "this account has no
            // domains", which is the failure worth being loud about on an API used to manage
            // DNS.
            throw MalformedResponseException::forResponse(
                $request->getMethod(),
                (string) $request->getUri(),
                $response,
                $body
            );
        }

        // RAISED, NOT LOGGED. Whether this is a failure is decided by whoever catches it, and
        // this package catches some of its own: apiFind() turns a 404 into null, and
        // Account::find() turns a scope refusal into null. Logged at `error` here first, both
        // reported an ordinary answer as a fault - a find() for a zone that is not there paged
        // whoever routes error logs to an alerting channel - and every failure a caller did log
        // arrived twice. The exception carries what the log line did: status, body, errors.
        throw ApiException::fromResponse(
            $request->getMethod(),
            (string) $request->getUri(),
            $response,
            $decoded,
            $body
        );
    }

    /**
     * Log the request at `debug`, send it, and keep a transport failure distinct from an HTTP
     * status. A PSR-18 client throws only for the former, which is what makes that separation
     * free.
     *
     * `debug` is the only level this package logs at. A failure is raised rather than logged -
     * see send() for why - so the request line is the whole of what reaches a logger.
     *
     * The catch is ClientExceptionInterface and not \Throwable, deliberately. Anything else
     * a client throws is not a transport failure and must not be dressed as one: Laravel's
     * StrayRequestException, raised by `Http::preventStrayRequests()` when a request escapes
     * the fakes, is a plain RuntimeException, and it reaches the consumer's test naming the
     * URL only because it passes through here untouched. Widened, it would arrive as "could
     * not reach the Linode API", which is the wrong diagnosis in the one place a wrong
     * diagnosis costs most.
     */
    private function dispatch(RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $uri = (string) $request->getUri();

        $this->logger->debug('Linode API request', [
            'method' => $method,
            'uri' => $uri,
            'filter' => $request->getHeaderLine('X-Filter') ?: null,
        ]);

        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw RequestException::for($method, $uri, $e);
        }
    }

    /**
     * Nothing has failed, but the window is nearly spent. Logged at `debug` like everything
     * else here: it is not a failure, and a warning would page an alerting channel for a bulk
     * job that is behaving correctly. A caller that wants to act on it reads
     * `ResponseMeta::isNearingRateLimit()`, which is on every response.
     */
    private function noteRateLimit(RequestInterface $request, ResponseMeta $meta): void
    {
        if ($meta->isNearingRateLimit()) {
            $this->logger->debug('Linode API rate limit is nearly spent', [
                'uri' => (string) $request->getUri(),
                ...$meta->toArray(),
            ]);
        }
    }
}
