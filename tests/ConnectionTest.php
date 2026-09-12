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
     * The status is what separates these, and each one sends a caller somewhere different -
     * a 401 to the credential, a 403 to its scopes, a 404 to the id.
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
