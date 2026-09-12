<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\Linode\Api\Authentication\AccessToken;
use Hampel\Linode\Api\Client;
use Hampel\Linode\Api\Config;
use Hampel\Linode\Api\Endpoint\Account;
use Hampel\Linode\Api\Endpoint\DomainRecords;
use Hampel\Linode\Api\Endpoint\Domains;
use Hampel\Linode\Api\Endpoint\Endpoint;
use Hampel\Linode\Api\Endpoint\Profile;
use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Hampel\Linode\Api\Tests\Fixture\Instances;

final class ClientTest extends TestCase
{
    public function test_the_named_accessors_return_their_endpoints_and_memoise(): void
    {
        $linode = $this->linode();

        $this->assertInstanceOf(Profile::class, $linode->profile());
        $this->assertInstanceOf(Account::class, $linode->account());
        $this->assertInstanceOf(Domains::class, $linode->domains());
        $this->assertInstanceOf(DomainRecords::class, $linode->records());

        $this->assertSame($linode->domains(), $linode->domains(), 'constructed once');
    }

    public function test_the_short_constructor_takes_a_token_and_a_transport(): void
    {
        $this->client->pushJson(200, []);

        $linode = Client::withToken('test-token-000000000000abcd', $this->client);
        $linode->connection()->get('profile');

        $this->assertSame('https://api.linode.com/v4/profile', (string) $this->client->lastRequest()->getUri());
        $this->assertSame('Bearer test-token-000000000000abcd', $this->client->lastRequest()->getHeaderLine('Authorization'));
    }

    /**
     * The PSR-17 factories are optional: a consumer who does not care which HTTP library is
     * involved should not have to name one.
     */
    public function test_the_psr17_factories_are_found_when_they_are_not_passed(): void
    {
        $this->client->pushJson(200, []);

        $linode = new Client(new Config(), new AccessToken('t-000000000000abcd'), $this->client);
        $linode->connection()->get('profile');

        $this->assertCount(1, $this->client->requests);
    }

    public function test_an_arbitrary_endpoint_class_is_constructed_and_memoised(): void
    {
        $linode = $this->linode();

        $this->assertInstanceOf(Instances::class, $linode->endpoint(Instances::class));
        $this->assertSame($linode->endpoint(Instances::class), $linode->endpoint(Instances::class));
    }

    /**
     * The extension point is the reason the package does not have to wrap all three hundred
     * endpoints - so it is asserted rather than assumed.
     */
    public function test_an_endpoint_this_package_never_wrapped_works_end_to_end(): void
    {
        $this->client->pushJson(200, $this->collection([
            ['id' => 1, 'label' => 'web-1'],
            ['id' => 2, 'label' => 'web-2'],
        ]));

        $labels = $this->linode()->endpoint(Instances::class)->labels();

        $this->assertSame(['web-1', 'web-2'], $labels);
        $this->assertSame('/v4/linode/instances', $this->sentPath());
    }

    public function test_the_abstract_base_cannot_be_constructed_as_an_endpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('concrete subclass');

        $this->linode()->endpoint(Endpoint::class);
    }

    public function test_a_class_that_is_not_an_endpoint_at_all_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type, argument.templateType */
        $this->linode()->endpoint(Config::class);
    }

    public function test_a_second_credential_gets_its_own_client_rather_than_mutating_this_one(): void
    {
        $this->client->pushJson(200, []);
        $this->client->pushJson(200, []);

        $first = $this->linode();
        $second = $first->withCredential(new AccessToken('other-token-00000000wxyz'));

        $this->assertNotSame($first, $second);

        $first->connection()->get('profile');
        $this->assertSame('Bearer test-token-000000000000abcd', $this->client->lastRequest()->getHeaderLine('Authorization'));

        $second->connection()->get('profile');
        $this->assertSame('Bearer other-token-00000000wxyz', $this->client->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_the_beta_api_is_a_second_client_because_it_moves_every_request(): void
    {
        $this->client->pushJson(200, []);

        $beta = $this->linode()->withVersion(Config::VERSION_BETA);
        $beta->connection()->get('domains');

        $this->assertTrue($beta->config()->isBeta());
        $this->assertSame('/v4beta/domains', $this->sentPath());
    }

    public function test_a_derived_client_keeps_the_configured_page_size(): void
    {
        $linode = $this->linode(new Config(pageSize: 250))->withVersion(Config::VERSION_BETA);

        $this->assertSame(250, $linode->config()->pageSize);
    }

    public function test_the_credential_describes_itself_without_printing_itself(): void
    {
        $token = new AccessToken('a-very-long-linode-token-value-1234');

        $description = $token->describe();

        $this->assertStringContainsString('1234', $description);
        $this->assertStringNotContainsString('a-very-long-linode-token-value', $description);
        $this->assertSame($description, (string) $token);
        $this->assertSame(['token' => $description], $token->__debugInfo());
    }

    public function test_a_short_token_is_not_partly_printed(): void
    {
        $this->assertSame('a Linode API token of 5 characters', (new AccessToken('short'))->describe());
    }

    public function test_an_empty_token_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AccessToken('   ');
    }

    public function test_the_credential_sets_the_bearer_header(): void
    {
        $factory = new HttpFactory();
        $request = (new AccessToken('abc'))->applyTo($factory->createRequest('GET', 'https://api.linode.com/v4/profile'));

        $this->assertSame('Bearer abc', $request->getHeaderLine('Authorization'));
    }
}
