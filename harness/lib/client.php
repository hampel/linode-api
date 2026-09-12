<?php

/**
 * Not an exercise - see lib/agent.php. Builds the client every exercise needs, from the
 * environment, and fails with something legible when it cannot.
 */

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\Linode\Api\Authentication\AccessToken;
use Hampel\Linode\Api\Client;
use Hampel\Linode\Api\Config;
use Hampel\Rig\Io;

function harness_client(Io $io): Client
{
    $token = getenv('LINODE_TOKEN');

    if (!is_string($token) || $token === '') {
        $io->error('LINODE_TOKEN is not set. Copy .env.example to .env beside the package.');
        $io->error('If you are an agent and the rig said it withheld the environment file, that is the guard');
        $io->error('working - ask rather than working around it.');

        exit(1);
    }

    $version = getenv('LINODE_API_VERSION');

    $factory = new HttpFactory();

    return new Client(
        new Config(is_string($version) && $version !== '' ? $version : Config::VERSION_STABLE),
        new AccessToken($token),
        new Guzzle(),
        $factory,
        $factory
    );
}

/**
 * The zone an exercise is pointed at, by name. Nothing here guesses one: an exercise that
 * picked "the first domain on the account" would do something different on every account it
 * ran against, and on one of them that would be the wrong zone.
 */
function harness_domain(Io $io): string
{
    $domain = getenv('LINODE_DOMAIN');

    if (!is_string($domain) || $domain === '') {
        $io->error('LINODE_DOMAIN is not set - name the zone this exercise should work with.');

        exit(1);
    }

    return $domain;
}
