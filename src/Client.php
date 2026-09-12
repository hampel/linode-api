<?php

declare(strict_types=1);

namespace Hampel\Linode\Api;

use Hampel\Linode\Api\Authentication\AccessToken;
use Hampel\Linode\Api\Authentication\Authentication;
use Hampel\Linode\Api\Endpoint\Account;
use Hampel\Linode\Api\Endpoint\DomainRecords;
use Hampel\Linode\Api\Endpoint\Domains;
use Hampel\Linode\Api\Endpoint\Endpoint;
use Hampel\Linode\Api\Endpoint\Profile;
use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Hampel\Linode\Api\Result\TokenStatus;
use Hampel\Linode\Api\Support\Psr17Discovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The entry point. Hand it a token and any PSR-18 client:
 *
 *     $guzzle  = new GuzzleHttp\Client();
 *     $factory = new GuzzleHttp\Psr7\HttpFactory();   // PSR-17, both roles
 *
 *     $linode = new Client(new Config(), new AccessToken($token), $guzzle, $factory, $factory);
 *
 *     $linode->verify();                              // does this token work?
 *     $linode->domains()->findByName('example.com');
 *
 * Or, for the ordinary case where the answer to every construction question is the default:
 *
 *     $linode = Client::withToken($token, $guzzle);
 *
 * EXTENDING IT. This package wraps the DNS endpoints and the two that identify a token,
 * which is a dozen of Linode's three hundred. The rest are reachable without waiting for a
 * release, and in two ways:
 *
 *     $linode->connection()->get('linode/instances')->array('data');   // once
 *     $linode->endpoint(Instances::class)->all();                      // more than once
 *
 * The second is the one to build on - see Endpoint.
 */
final class Client
{
    private readonly Connection $connection;

    /** @var array<class-string<Endpoint>, Endpoint> */
    private array $endpoints = [];

    /**
     * @param  RequestFactoryInterface|null  $requestFactory  PSR-17. Leave both null and the
     *         package finds one - Guzzle's, Nyholm's or Diactoros', whichever is installed;
     *         see Psr17Discovery. Pass them to choose, or when none of those is present.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Authentication $authentication,
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($requestFactory === null || $streamFactory === null) {
            [$foundRequest, $foundStream] = Psr17Discovery::find();

            $requestFactory ??= $foundRequest;
            $streamFactory ??= $foundStream;
        }

        $this->connection = new Connection(
            $this->config,
            $this->authentication,
            $client,
            $requestFactory,
            $streamFactory,
            $this->logger
        );
    }

    /**
     * The short form: a personal access token, the default configuration, and a transport.
     *
     * Everything the long constructor takes is still available on it; this exists because
     * naming a Config and an AccessToken to accept both defaults is ceremony, and ceremony in
     * an example is what gets copied.
     */
    public static function withToken(
        #[\SensitiveParameter] string $token,
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            new Config(),
            new AccessToken($token),
            $client,
            $requestFactory,
            $streamFactory,
            $logger ?? new NullLogger()
        );
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
     * Does this token work, and what may it do - in one request.
     *
     * The call to make at startup, before anything that matters. Raises
     * NotAuthenticatedException for a token that is not valid; see TokenStatus for why that
     * is a throw rather than a boolean.
     */
    public function verify(): TokenStatus
    {
        return $this->profile()->verify();
    }

    /**
     * The same account and transport, under a different credential.
     *
     * A new client rather than a mutation, so two credentials in one long-running process -
     * a job that walks several accounts - cannot leak into each other's requests. Endpoints
     * are not carried over: they hold the connection this one is replacing.
     */
    public function withCredential(Authentication $authentication): self
    {
        return new self(
            $this->config,
            $authentication,
            $this->connection->client(),
            $this->connection->requestFactory(),
            $this->connection->streamFactory(),
            $this->logger
        );
    }

    /**
     * The same credential and transport, against the beta API - or back against the stable
     * one.
     *
     * The version is a URL segment rather than a header, so it moves every request this
     * client makes. That is why it is a second client rather than a flag on a call.
     */
    public function withVersion(string $version): self
    {
        return new self(
            new Config($version, $this->config->baseUri, $this->config->pageSize),
            $this->authentication,
            $this->connection->client(),
            $this->connection->requestFactory(),
            $this->connection->streamFactory(),
            $this->logger
        );
    }

    /**
     * Any Endpoint subclass, constructed and memoised.
     *
     * This is the extension point. A third-party package ships an Endpoint subclass for the
     * endpoints it needs, a consumer names the class, and static analysis follows the return
     * type through - there is nothing to register, no container and no string keys. The
     * named accessors below are the same mechanism with a shorter name.
     *
     * @template T of Endpoint
     * @param  class-string<T>  $class
     * @return T
     */
    public function endpoint(string $class): Endpoint
    {
        if (!isset($this->endpoints[$class])) {
            // Both halves earn their place. The first catches a class that is not an
            // Endpoint at all, which static analysis already rejects but a caller without it
            // can still write. The second catches Endpoint itself and any abstract subclass -
            // both of which satisfy class-string<Endpoint>, so nothing but this stands
            // between them and a fatal error on `new`.
            if (!is_subclass_of($class, Endpoint::class) || !(new \ReflectionClass($class))->isInstantiable()) {
                throw new InvalidArgumentException(sprintf(
                    '%s cannot be constructed as an API endpoint: it must be a concrete subclass of %s.',
                    $class,
                    Endpoint::class
                ));
            }

            $this->endpoints[$class] = new $class($this->connection, $this->logger);
        }

        /** @var T $endpoint */
        $endpoint = $this->endpoints[$class];

        return $endpoint;
    }

    /**
     * The user the token belongs to, and the cheapest check that it works at all.
     */
    public function profile(): Profile
    {
        return $this->endpoint(Profile::class);
    }

    /**
     * The billing account - which needs `account:read_only`, a scope a DNS token will not
     * usually have.
     */
    public function account(): Account
    {
        return $this->endpoint(Account::class);
    }

    /**
     * DNS zones.
     */
    public function domains(): Domains
    {
        return $this->endpoint(Domains::class);
    }

    /**
     * Records, with the domain id as the first argument to every call. For several calls
     * against one zone, `domains()->records($id)` binds it once.
     */
    public function records(): DomainRecords
    {
        return $this->endpoint(DomainRecords::class);
    }

    /**
     * For an endpoint nothing here wraps - which is most of this API. Call it directly
     * rather than waiting for a version of this package.
     */
    public function connection(): Connection
    {
        return $this->connection;
    }
}
