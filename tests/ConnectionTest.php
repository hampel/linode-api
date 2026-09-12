<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Hampel\Linode\Api\Connection;
use Hampel\Linode\Api\Exception\ApiException;
use Hampel\Linode\Api\Exception\MalformedResponseException;
use Hampel\Linode\Api\Exception\NotAuthenticatedException;
use Hampel\Linode\Api\Exception\NotFoundException;
use Hampel\Linode\Api\Exception\NotPermittedException;
use Hampel\Linode\Api\Exception\RequestException;
use Hampel\Linode\Api\Exception\ServerException;
use Hampel\Linode\Api\Exception\TooManyRequestsException;
use Hampel\Linode\Api\Exception\ValidationException;
use Hampel\Linode\Api\Support\Filter;

final class ConnectionTest extends TestCase
{
    private function connection(): Connection
    {
        return $this->linode()->connection();
    }

    public function test_it_presents_the_token_as_a_bearer_credential(): void
    {
        $this->client->pushJson(200, []);
        $this->connection()->get('profile');

        $request = $this->client->lastRequest();

        $this->assertSame('Bearer test-token-000000000000abcd', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function test_a_body_is_json_with_a_bare_content_type(): void
    {
        $this->client->pushJson(200, []);
        $this->connection()->post('domains', ['domain' => 'example.com', 'type' => 'master']);

        $request = $this->client->lastRequest();

        $this->assertSame('POST', $request->getMethod());
        // No charset parameter - see Connection::JSON_CONTENT_TYPE
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame(['domain' => 'example.com', 'type' => 'master'], $this->sentBody());
    }

    public function test_a_filter_travels_as_a_header_not_a_query_parameter(): void
    {
        $this->client->pushJson(200, $this->collection([]));
        $this->connection()->get('domains', [], Filter::where('domain', 'example.com'));

        $this->assertSame('{"domain":"example.com"}', $this->client->lastRequest()->getHeaderLine('X-Filter'));
        $this->assertSame('', $this->sentQuery());
    }

    public function test_an_empty_filter_sends_no_header_at_all(): void
    {
        $this->client->pushJson(200, $this->collection([]));
        $this->connection()->get('domains', [], Filter::make());

        $this->assertFalse($this->client->lastRequest()->hasHeader('X-Filter'));
    }

    public function test_a_delete_answering_an_empty_object_is_a_success(): void
    {
        $this->client->pushJson(200, []);

        $response = $this->connection()->delete('domains/1');

        $this->assertTrue($response->isEmpty());
        $this->assertSame(200, $response->status);
    }

    public function test_a_204_with_no_body_is_a_success(): void
    {
        $this->client->pushRaw(204, '');

        $this->assertTrue($this->connection()->get('profile/grants')->isEmpty());
    }

    /**
     * 204 is the ONLY success with a legitimately empty body, and the distinction is measured
     * rather than assumed: a successful DELETE answers 200 with `Content-Length: 2` and a body
     * of `{}`, read off the live API on 2026-09-13, so it decodes and never needs this path.
     *
     * An empty 200 therefore means something other than Linode answered - or, far more often,
     * that a consumer's test fake has no body. Laravel's `Http::fake()` with no arguments
     * answers every request with exactly this, which is why an earlier version of the check
     * accepted any empty-bodied 2xx and let a forgotten fake read as "no zones on this
     * account". The first consumer of this package found that, which is the one direction
     * nobody was looking.
     */
    public function test_an_empty_bodied_200_raises_rather_than_reading_as_no_records(): void
    {
        $this->client->pushRaw(200, '');

        try {
            $this->connection()->get('domains');
            $this->fail('an empty 200 did not raise');
        } catch (MalformedResponseException $e) {
            $this->assertStringContainsString('the body was empty', $e->getMessage());
            $this->assertInstanceOf(ApiException::class, $e, 'an existing catch should see it');
        }
    }

    /**
     * The whole point of the narrowing, expressed the way a consumer meets it: the endpoint
     * answers a list, the fake forgot to say so, and the client must not report an empty
     * account.
     */
    public function test_a_fake_with_no_body_cannot_read_as_an_empty_collection(): void
    {
        $this->client->pushRaw(200, '');

        $this->expectException(MalformedResponseException::class);

        $this->linode()->domains()->all();
    }

    public function test_whitespace_alone_is_not_a_body_either(): void
    {
        $this->client->pushRaw(200, "\n  \n");

        $this->expectException(MalformedResponseException::class);

        $this->connection()->get('domains');
    }

    /**
     * The measured shape of a real successful delete, which must keep working: two bytes that
     * decode to an empty object, not an empty body.
     */
    public function test_the_measured_delete_response_is_still_a_success(): void
    {
        $this->client->pushRaw(200, '{}', ['Content-Type' => 'application/json']);

        $response = $this->connection()->delete('domains/1234/records/5678');

        $this->assertTrue($response->isEmpty());
        $this->assertSame(200, $response->status);
    }

    /**
     * Each type sends whoever reads it somewhere different - a 401 to the credential, a 403
     * to the grants, a 404 to the id.
     */
    public function test_each_status_maps_to_the_type_that_says_what_to_do_about_it(): void
    {
        $cases = [
            400 => ValidationException::class,
            401 => NotAuthenticatedException::class,
            403 => NotPermittedException::class,
            404 => NotFoundException::class,
            429 => TooManyRequestsException::class,
            500 => ServerException::class,
            503 => ServerException::class,
        ];

        foreach ($cases as $status => $expected) {
            $this->client->pushJson($status, $this->errors([['reason' => 'nope']]));

            try {
                $this->connection()->get('domains');
                $this->fail(sprintf('HTTP %d did not raise', $status));
            } catch (ApiException $e) {
                $this->assertInstanceOf($expected, $e, sprintf('HTTP %d', $status));
                $this->assertSame($status, $e->statusCode);
            }
        }
    }

    /**
     * THE ONE PLACE THE TYPE DOES NOT FOLLOW THE STATUS, and it is not a preference - both
     * of these are 401 on the live API, measured on 12 September 2026 with a token holding
     * `domains:read_write` and nothing else:
     *
     *   401  X-OAuth-Scopes: domains:read_write  "Your OAuth token is not authorized ..."
     *   401  X-OAuth-Scopes: unknown             "Invalid Token"
     *
     * They need opposite responses - widen the token's scopes, against replace it - so
     * collapsing both into one type would put the consumer back to matching on the reason
     * string. Linode can only report a token's scopes for a token it recognises, so the
     * header is the discriminator, and it is a fact rather than prose.
     */
    public function test_a_401_that_names_the_tokens_own_scopes_is_a_scope_failure_not_a_bad_token(): void
    {
        $this->client->pushJson(401, $this->errors([
            ['reason' => 'Your OAuth token is not authorized to use this endpoint.'],
        ]), [
            'X-OAuth-Scopes' => 'domains:read_write',
            'X-Accepted-OAuth-Scopes' => 'account:read_only',
        ]);

        try {
            $this->connection()->get('account');
            $this->fail('did not raise');
        } catch (NotPermittedException $e) {
            $this->assertSame(401, $e->statusCode, 'the status really is 401');
            $this->assertTrue($e->isScopeFailure());
            $this->assertSame('account:read_only', (string) $e->requiredScopes());
            $this->assertSame('domains:read_write', (string) $e->heldScopes());

            // The message has to say so, because the status contradicts it and the message is
            // what most people read.
            $this->assertStringContainsString('scope failure, despite the 401', $e->getMessage());
            $this->assertStringContainsString('account:read_only', $e->getMessage());
        }
    }

    public function test_a_401_with_unknown_scopes_is_a_bad_credential(): void
    {
        $this->client->pushJson(401, $this->errors([['reason' => 'Invalid Token']]), [
            'X-OAuth-Scopes' => 'unknown',
            'X-Accepted-OAuth-Scopes' => 'account:read_only',
        ]);

        try {
            $this->connection()->get('account');
            $this->fail('did not raise');
        } catch (NotAuthenticatedException $e) {
            $this->assertStringNotContainsString('scope failure', $e->getMessage());
        }
    }

    /**
     * A proxy that strips the header leaves nothing to discriminate on. Degrading to "your
     * credential is wrong" is the conservative reading: it sends the operator to look at the
     * token, which is where they would start anyway.
     */
    public function test_a_401_with_the_scope_header_stripped_degrades_to_a_bad_credential(): void
    {
        $this->client->pushJson(401, $this->errors([['reason' => 'Invalid Token']]));

        $this->expectException(NotAuthenticatedException::class);
        $this->connection()->get('account');
    }

    /**
     * A restricted user refused an object they have no grant for is a genuine 403, and still
     * reaches NotPermittedException - just not as a scope failure.
     */
    public function test_a_403_is_permission_but_not_a_scope_failure(): void
    {
        $this->client->pushJson(403, $this->errors([['reason' => 'Unauthorized']]), [
            'X-OAuth-Scopes' => 'domains:read_write',
            'X-Accepted-OAuth-Scopes' => 'domains:read_write',
        ]);

        try {
            $this->connection()->get('domains/1');
            $this->fail('did not raise');
        } catch (NotPermittedException $e) {
            $this->assertSame(403, $e->statusCode);
            $this->assertFalse($e->isScopeFailure(), 'the token holds what the endpoint wanted');
        }
    }

    public function test_the_response_metadata_reaches_the_exception(): void
    {
        $this->client->pushJson(429, $this->errors([['reason' => 'Too many requests']]), [
            'X-RateLimit-Limit' => '1840',
            'X-RateLimit-Remaining' => '0',
            'Retry-After' => '60',
        ]);

        try {
            $this->connection()->get('domains');
            $this->fail('did not raise');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(0, $e->meta->rateLimitRemaining);
            $this->assertSame(1840, $e->meta->rateLimit);
            $this->assertSame(60, $e->retryAfter);
        }
    }

    public function test_an_error_carries_linodes_reason_and_field(): void
    {
        $this->client->pushJson(400, $this->errors([
            ['field' => 'page_size', 'reason' => 'Must be 25-500'],
            ['reason' => 'Something else'],
        ]));

        try {
            $this->connection()->get('domains');
            $this->fail('did not raise');
        } catch (ValidationException $e) {
            $this->assertSame(['Must be 25-500', 'Something else'], $e->reasons());
            $this->assertSame(['page_size' => ['Must be 25-500']], $e->fieldErrors());
            $this->assertTrue($e->concerns('page_size'));
            $this->assertFalse($e->concerns('domain'));
            $this->assertStringContainsString('page_size: Must be 25-500', $e->getMessage());
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
        }
    }

    /**
     * The exact reply the live API gives for a missing header and for a made-up token, both
     * measured on 12 September 2026. They are indistinguishable, which is why the exception
     * message cannot claim to know which happened.
     */
    public function test_an_invalid_token_is_reported_as_authentication(): void
    {
        $this->client->pushJson(401, $this->errors([['reason' => 'Invalid Token']]));

        $this->expectException(NotAuthenticatedException::class);
        $this->expectExceptionMessage('Invalid Token');

        $this->connection()->get('profile');
    }

    /**
     * The failure this package exists to make loud: a 200 with a maintenance page in it
     * decodes to nothing, and nothing reads downstream as "the account has no domains".
     */
    public function test_a_200_that_is_not_json_raises_rather_than_reading_as_empty(): void
    {
        $this->client->pushRaw(200, '<html><body>Down for maintenance</body></html>', ['Content-Type' => 'text/html']);

        try {
            $this->connection()->get('domains');
            $this->fail('did not raise');
        } catch (MalformedResponseException $e) {
            $this->assertInstanceOf(ApiException::class, $e, 'an existing catch should see it');
            $this->assertStringContainsString('not JSON', $e->getMessage());
            $this->assertStringContainsString('text/html', $e->getMessage());
        }
    }

    public function test_a_transport_failure_is_not_an_api_error(): void
    {
        $this->client->push(new TransportFailure($this->linode()->connection()->request('GET', 'domains')));

        try {
            $this->connection()->get('domains');
            $this->fail('did not raise');
        } catch (RequestException $e) {
            $this->assertStringContainsString('Could not reach the Linode API', $e->getMessage());
        }

        // Asserted on the class rather than the instance: the separation is a fact about the
        // hierarchy, and a `catch (ApiException)` that started swallowing transport failures
        // would be a silent change of meaning for every consumer.
        $this->assertNotContains(ApiException::class, class_parents(RequestException::class) ?: []);
    }

    /**
     * Laravel's StrayRequestException is a plain RuntimeException, and it must arrive at the
     * consumer's test with its own message rather than dressed as a transport failure.
     */
    public function test_something_that_is_not_a_psr18_exception_passes_straight_through(): void
    {
        $this->client->pushThrowable(new \RuntimeException('Attempted request to [https://api.linode.com] without a matching fake.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('without a matching fake');

        $this->connection()->get('domains');
    }

    public function test_the_rate_limit_and_scopes_come_back_on_every_response(): void
    {
        $this->client->pushJson(200, [], [
            'X-RateLimit-Limit' => '1840',
            'X-RateLimit-Remaining' => '1839',
            'X-RateLimit-Reset' => '1789212835',
            'Retry-After' => '60',
            'X-OAuth-Scopes' => 'domains:read_write account:read_only',
            'X-Accepted-OAuth-Scopes' => '*',
            'X-Spec-Version' => '4.235.1',
        ]);

        $meta = $this->connection()->get('profile')->meta;

        $this->assertSame(1840, $meta->rateLimit);
        $this->assertSame(1839, $meta->rateLimitRemaining);
        $this->assertSame(60, $meta->retryAfter, 'present on a 200 - it is not a throttle signal');
        $this->assertSame('4.235.1', $meta->specVersion);
        $this->assertTrue($meta->scopes->allows('domains:read_only'));
        $this->assertFalse($meta->isNearingRateLimit());
    }

    public function test_it_warns_once_the_rate_limit_window_is_nearly_spent(): void
    {
        $logger = new RecordingLogger();

        $this->client->pushJson(200, [], [
            'X-RateLimit-Limit' => '1000',
            'X-RateLimit-Remaining' => '20',
        ]);

        $this->linode(logger: $logger)->connection()->get('domains');

        $context = $logger->contextFor('Linode API rate limit is nearly spent');

        $this->assertNotNull($context);
        $this->assertSame(20, $context['rate_limit_remaining']);
    }

    public function test_a_failure_is_logged_with_the_decoded_body(): void
    {
        $logger = new RecordingLogger();
        $this->client->pushJson(404, $this->errors([['reason' => 'Not found']]));

        try {
            $this->linode(logger: $logger)->connection()->get('domains/1');
        } catch (NotFoundException) {
            // expected
        }

        $context = $logger->contextFor('Linode API error response');

        $this->assertNotNull($context);
        $this->assertSame(404, $context['status']);
    }

    public function test_a_put_carries_only_the_fields_it_was_given(): void
    {
        $this->client->pushJson(200, []);
        $this->connection()->put('domains/1', ['ttl_sec' => 300]);

        $this->assertSame('PUT', $this->client->lastRequest()->getMethod());
        $this->assertSame(['ttl_sec' => 300], $this->sentBody());
    }

    public function test_the_transport_and_factories_are_reachable_for_a_caller_building_its_own_request(): void
    {
        $connection = $this->connection();

        $this->assertSame($this->client, $connection->client());
        $this->assertSame('api.linode.com', $connection->config()->host());

        // A request built through the exposed factories carries the credential and resolves
        // against the same base as everything else, which is the point of exposing them.
        $request = $connection->request('GET', 'linode/instances');

        $this->assertSame('https://api.linode.com/v4/linode/instances', (string) $request->getUri());
        $this->assertSame('Bearer test-token-000000000000abcd', $request->getHeaderLine('Authorization'));
        $this->assertSame('', (string) $connection->streamFactory()->createStream(''));
        $this->assertStringContainsString('abcd', $connection->authentication()->describe());
    }
}
