<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\Linode\Api\Authentication\AccessToken;
use Hampel\Linode\Api\Authentication\Authentication;
use Hampel\Linode\Api\Client;
use Hampel\Linode\Api\Config;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class TestCase extends BaseTestCase
{
    protected StubClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new StubClient();
    }

    protected function linode(
        ?Config $config = null,
        ?LoggerInterface $logger = null,
        ?Authentication $authentication = null,
    ): Client {
        $factory = new HttpFactory();

        return new Client(
            $config ?? new Config(),
            $authentication ?? new AccessToken('test-token-000000000000abcd'),
            $this->client,
            $factory,
            $factory,
            $logger ?? new NullLogger()
        );
    }

    /**
     * The body of the last request the stub client was given, decoded.
     *
     * @return array<mixed>
     */
    protected function sentBody(): array
    {
        $decoded = json_decode((string) $this->client->lastRequest()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The path of the last request, without the host - what an assertion about routing
     * actually cares about.
     */
    protected function sentPath(): string
    {
        return $this->client->lastRequest()->getUri()->getPath();
    }

    protected function sentQuery(): string
    {
        return $this->client->lastRequest()->getUri()->getQuery();
    }

    /**
     * The X-Filter header of the last request, decoded.
     *
     * @return array<mixed>
     */
    protected function sentFilter(): array
    {
        $decoded = json_decode($this->client->lastRequest()->getHeaderLine('X-Filter'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A collection envelope in the shape every list endpoint on this API answers with.
     *
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    protected function collection(array $data, int $page = 1, int $pages = 1, ?int $results = null): array
    {
        return [
            'data' => $data,
            'page' => $page,
            'pages' => $pages,
            'results' => $results ?? count($data),
        ];
    }

    /**
     * An error body in Linode's shape.
     *
     * @param  list<array<string, string>>  $errors
     * @return array<string, mixed>
     */
    protected function errors(array $errors): array
    {
        return ['errors' => $errors];
    }
}
