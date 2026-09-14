<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Hampel\Linode\Api\Entity\DomainRecord;
use Hampel\Linode\Api\Exception\ExceptionInterface;
use Hampel\Linode\Api\Exception\MalformedResponseException;
use Hampel\Linode\Api\Exception\NotFoundException;
use Hampel\Linode\Api\Exception\RequestException;
use Hampel\Linode\Api\Exception\ServerException;
use Hampel\Linode\Api\Exception\UnexpectedResponseException;

/**
 * The client does not log a failure it raises. It logs at `debug`, and at nothing higher.
 *
 * Whether an exception is a failure is decided by whoever catches it, and the client catches
 * some of its own: find() turns a 404 into null, and Account::find() turns a scope refusal into
 * null. Logged at `error` before the throw, both reported an ordinary answer as a fault - a
 * find() for a zone that was not there paged whoever routed error logs to an alerting channel -
 * and every failure a caller did log arrived twice. Writes were logged too, a delete at
 * `warning`, so an ordinary successful delete did the same.
 *
 * Each case asserts nothing reached a level above `debug`, which is what would reach an alerting
 * channel. The same convention as hampel/binarylane-api.
 */
final class LoggingTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    private function assertRaisedWithoutLogging(string $expected, callable $call): void
    {
        try {
            $call();
            $this->fail('expected ' . $expected);
        } catch (ExceptionInterface $e) {
            $this->assertInstanceOf($expected, $e);
        }

        $this->assertSame([], $this->logger->aboveDebug());
    }

    public function test_a_find_that_finds_nothing_logs_nothing_above_debug(): void
    {
        $this->client->pushJson(404, $this->errors([['reason' => 'Not found']]));

        $this->assertNull($this->linode(logger: $this->logger)->domains()->find(1234));
        $this->assertSame([], $this->logger->aboveDebug());
    }

    public function test_a_lookup_by_name_that_finds_nothing_logs_nothing_above_debug(): void
    {
        $this->client->pushJson(200, $this->collection([]));

        $this->assertNull($this->linode(logger: $this->logger)->domains()->findByName('example.com'));
        $this->assertSame([], $this->logger->aboveDebug());
    }

    public function test_an_account_the_token_may_not_read_logs_nothing_above_debug(): void
    {
        $this->client->pushJson(401, $this->errors([
            ['reason' => 'Your OAuth token is not authorized to use this endpoint.'],
        ]), ['X-OAuth-Scopes' => 'domains:read_write', 'X-Accepted-OAuth-Scopes' => 'account:read_only']);

        $this->assertNull($this->linode(logger: $this->logger)->account()->find());
        $this->assertSame([], $this->logger->aboveDebug());
    }

    public function test_a_missing_record_is_raised_and_not_logged(): void
    {
        $this->client->pushJson(404, $this->errors([['reason' => 'Not found']]));

        $this->assertRaisedWithoutLogging(
            NotFoundException::class,
            fn () => $this->linode(logger: $this->logger)->domains()->get(1234)
        );
    }

    public function test_a_server_failure_is_raised_and_not_logged(): void
    {
        $this->client->pushJson(500, $this->errors([['reason' => 'Internal error']]));

        $this->assertRaisedWithoutLogging(
            ServerException::class,
            fn () => $this->linode(logger: $this->logger)->domains()->get(1234)
        );
    }

    public function test_a_transport_failure_is_raised_and_not_logged(): void
    {
        $this->client->push(new TransportFailure($this->linode()->connection()->request('GET', 'domains')));

        $this->assertRaisedWithoutLogging(
            RequestException::class,
            fn () => $this->linode(logger: $this->logger)->domains()->get(1234)
        );
    }

    public function test_a_body_that_is_not_json_is_raised_and_not_logged(): void
    {
        $this->client->pushRaw(200, '<html>Down for maintenance</html>', ['Content-Type' => 'text/html']);

        $this->assertRaisedWithoutLogging(
            MalformedResponseException::class,
            fn () => $this->linode(logger: $this->logger)->domains()->all()
        );
    }

    public function test_an_ignored_filter_is_raised_and_not_logged(): void
    {
        $this->client->pushJson(200, $this->collection([['id' => 1, 'domain' => 'other.example', 'type' => 'master']]));

        $this->assertRaisedWithoutLogging(
            UnexpectedResponseException::class,
            fn () => $this->linode(logger: $this->logger)->domains()->findByName('example.com')
        );
    }

    /**
     * Writes were logged at `info`, and a delete at `warning`, so an ordinary successful delete
     * reached an alerting channel. The request line at `debug` already records every call.
     */
    public function test_successful_writes_log_nothing_above_debug(): void
    {
        $linode = $this->linode(logger: $this->logger);
        $row = ['id' => 5678, 'type' => 'A', 'name' => 'www', 'target' => '203.0.113.10'];

        $this->client->pushJson(200, $row);
        $linode->records()->create(1234, DomainRecord::a('www', '203.0.113.10'));

        $this->client->pushJson(200, $row);
        $linode->records()->update(1234, 5678, ['ttl_sec' => 300]);

        $this->client->pushJson(200, []);
        $linode->records()->delete(1234, 5678);

        $this->client->pushJson(200, []);
        $linode->domains()->delete(1234);

        $this->assertSame([], $this->logger->aboveDebug());
        $this->assertCount(4, $this->logger->records, 'one debug request line per call, and nothing else');
    }

    public function test_the_token_never_reaches_the_log(): void
    {
        $this->client->pushJson(200, ['uid' => 1, 'username' => 'exampleuser'], ['X-OAuth-Scopes' => '*']);
        $this->client->pushJson(404, $this->errors([['reason' => 'Not found']]));

        $linode = $this->linode(logger: $this->logger);
        $linode->verify();
        $linode->domains()->find(1234);

        $this->assertNotSame([], $this->logger->records);
        $this->assertStringNotContainsString('test-token-000000000000abcd', serialize($this->logger->records));
    }
}
