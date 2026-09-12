<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Hampel\Linode\Api\Config;
use Hampel\Linode\Api\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class ConfigTest extends BaseTestCase
{
    public function test_it_defaults_to_the_stable_api_on_linodes_own_host(): void
    {
        $config = new Config();

        $this->assertSame('https://api.linode.com', $config->baseUri);
        $this->assertSame('api.linode.com', $config->host());
        $this->assertSame('v4', $config->version);
        $this->assertFalse($config->isBeta());
        $this->assertSame('https://api.linode.com/v4/domains', $config->resolve('domains'));
    }

    public function test_the_beta_api_is_a_url_segment(): void
    {
        $config = new Config(Config::VERSION_BETA);

        $this->assertTrue($config->isBeta());
        $this->assertSame('https://api.linode.com/v4beta/domains', $config->resolve('domains'));
    }

    public function test_an_unknown_version_is_refused_rather_than_404ing_every_call(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be "v4" or "v4beta"');

        new Config('v5');
    }

    /**
     * The documentation writes paths with the version on, so both forms get typed. Doubling
     * it would produce /v4/v4/domains and a 404 that reads like a missing endpoint.
     */
    public function test_a_path_that_already_carries_the_version_is_not_doubled(): void
    {
        $config = new Config();

        $this->assertSame('https://api.linode.com/v4/domains', $config->resolve('/v4/domains'));
        $this->assertSame('https://api.linode.com/v4/domains', $config->resolve('v4/domains'));
        $this->assertSame('https://api.linode.com/v4', $config->resolve('v4'));
    }

    /**
     * A v4beta path handed to a v4 client resolves against v4 - the client's version wins,
     * because the version belongs to the client and not to the call.
     */
    public function test_the_clients_version_wins_over_one_in_the_path(): void
    {
        $this->assertSame(
            'https://api.linode.com/v4/domains',
            (new Config())->resolve('v4beta/domains')
        );

        $this->assertSame(
            'https://api.linode.com/v4beta/domains',
            (new Config(Config::VERSION_BETA))->resolve('v4/domains')
        );
    }

    public function test_an_absolute_uri_passes_through_untouched(): void
    {
        $this->assertSame(
            'https://example.test/anything',
            (new Config())->resolve('https://example.test/anything')
        );
    }

    public function test_query_parameters_are_appended_and_nulls_dropped(): void
    {
        $uri = (new Config())->resolve('domains', ['page' => 2, 'page_size' => 25, 'unset' => null]);

        $this->assertSame('https://api.linode.com/v4/domains?page=2&page_size=25', $uri);
    }

    public function test_a_query_joins_an_existing_one_with_an_ampersand(): void
    {
        $uri = (new Config())->resolve('https://api.linode.com/v4/domains?page=2', ['page_size' => 25]);

        $this->assertSame('https://api.linode.com/v4/domains?page=2&page_size=25', $uri);
    }

    public function test_a_base_uri_can_be_pointed_somewhere_else_for_a_proxy_or_a_fixture(): void
    {
        $config = new Config(baseUri: 'http://127.0.0.1:8080/');

        $this->assertSame('http://127.0.0.1:8080', $config->baseUri);
        $this->assertSame('127.0.0.1', $config->host());
        $this->assertSame('http://127.0.0.1:8080/v4/domains', $config->resolve('domains'));
    }

    public function test_a_base_uri_without_a_scheme_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be absolute');

        new Config(baseUri: 'api.linode.com');
    }

    public function test_an_empty_base_uri_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(baseUri: '   ');
    }

    public function test_a_default_page_size_outside_linodes_range_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 25 and 500');

        new Config(pageSize: 10);
    }

    public function test_a_default_page_size_within_range_is_kept(): void
    {
        $this->assertSame(500, (new Config(pageSize: 500))->pageSize);
        $this->assertNull((new Config())->pageSize);
    }
}
