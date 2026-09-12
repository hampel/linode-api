<?php

/**
 * Exercise: provoke each failure this package distinguishes. Read-only.
 *
 * Each probe below checks that a status still becomes the exception type the hierarchy
 * assumes it does.
 *
 * READ-ONLY. Nothing here changes anything: every probe is a GET, and the one that uses a
 * credential uses a deliberately invalid one.
 *
 * The value of running it is that the mapping from status to exception type is an assumption
 * about a live API, and an API is free to change its mind. Each probe below prints what came
 * back as well as which type it became.
 *
 * Needs LINODE_TOKEN for the two probes that need a working credential.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\Linode\Api\Authentication\AccessToken;
use Hampel\Linode\Api\Client;
use Hampel\Linode\Api\Config;
use Hampel\Linode\Api\Exception\ApiException;
use Hampel\Linode\Api\Exception\NotAuthenticatedException;
use Hampel\Linode\Api\Exception\NotFoundException;
use Hampel\Linode\Api\Exception\ValidationException;

require __DIR__ . '/lib/client.php';

$io->title('linode · error shapes');

$linode = harness_client($io);

/**
 * @param  callable(): mixed  $probe
 */
$expect = function (string $what, string $expected, callable $probe) use ($io): void {
    try {
        $probe();
        $io->error(sprintf('✗ %s did not fail at all', $what));

        return;
    } catch (ApiException $e) {
        $actual = $e::class;

        $io->line(sprintf('  %-40s HTTP %-4d %s', $what, $e->statusCode, $actual === $expected ? '✓ ' . $actual : '✗ got ' . $actual));

        foreach ($e->errors as $error) {
            $io->line(sprintf('      %s', $error->describe()));
        }
    }
};

// 401: measured on 12 September 2026 as identical for a bogus token and for no header at
// all - "Invalid Token" either way.
$factory = new HttpFactory();
$bogus = new Client(new Config(), new AccessToken('not-a-real-token'), new Guzzle(), $factory, $factory);

$expect('a token that is not a token', NotAuthenticatedException::class, static fn () => $bogus->profile()->get());

// 400 with a field: the page size bound. This package refuses it before the request, so the
// probe goes through the connection directly - which is also the check that the bound is
// still 25.
$expect('page_size below the minimum', ValidationException::class, static fn () => $linode->connection()->get('domains', ['page_size' => 1]));

// 400 with a field, from the filter header rather than the body.
$expect('an X-Filter that is not JSON', ValidationException::class, static fn () => $linode->connection()->send(
    $linode->connection()->request('GET', 'domains')->withHeader('X-Filter', 'not json')
));

// 404: a zone id that will not exist.
$expect('a zone id that is not there', NotFoundException::class, static fn () => $linode->domains()->get(999999999));

// 404 again, for a path rather than a record - Linode does not distinguish them, which is
// why this package does not either.
$expect('a path the API does not have', NotFoundException::class, static fn () => $linode->connection()->get('no-such-endpoint'));

$io->line();

// The header that is on every response including the successful ones, and means nothing on
// its own.
$meta = $linode->connection()->get('profile')->meta;

$io->value('Retry-After on a 200', $meta->retryAfter === null ? '(absent)' : (string) $meta->retryAfter);
$io->info('If that printed a number, the header is not evidence of being throttled - only a 429 is.');
