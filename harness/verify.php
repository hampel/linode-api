<?php

/**
 * Exercise: prove the token works and report what it may do. Read-only.
 *
 * READ-ONLY, and the first thing to run against a new credential. Three requests at most:
 * the profile, the grants when the user is restricted, and the account when the token is
 * allowed to read it.
 *
 * It also prints two things this package could not verify from the specification, because
 * both need a real token, and this is where they get settled:
 *
 *   - the RAW `X-OAuth-Scopes` header, so the separator between multiple scopes is a fact
 *     rather than the tolerant guess in Result\Scopes;
 *   - the rate limit actually applied to an authenticated request, which the unauthenticated
 *     measurement of 1840 need not match.
 *
 * Needs LINODE_TOKEN.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\Linode\Api\Exception\ExceptionInterface;
use Hampel\Linode\Api\Exception\NotAuthenticatedException;

require __DIR__ . '/lib/client.php';

$io->title('linode · verify the token');

$linode = harness_client($io);

try {
    $status = $linode->verify();
} catch (NotAuthenticatedException $e) {
    $io->error('✗ the token is not valid');
    $io->value('message', $e->getMessage());
    $io->info('Linode answers the same "Invalid Token" for a missing, wrong, expired or revoked');
    $io->info('token, so there is nothing in the reply to say which of the four this is.');

    exit(1);
} catch (ExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());

    exit(1);
}

$io->success('✓ the token works');
$io->line();

$io->values([
    'username' => $status->username(),
    'uid' => (string) ($status->profile->uid ?? 0),
    'email' => (string) $status->profile->email,
    'restricted' => $status->isRestricted() ? 'yes' : 'no',
    'two-factor' => $status->profile->twoFactorAuth ? 'on' : 'off',
]);
$io->line();

// The unsettled question. Scopes::fromHeader() splits on commas and whitespace because the
// separator could not be measured without a token; this line is what settles it.
$io->values([
    'X-OAuth-Scopes (raw)' => $status->meta->scopes->raw === '' ? '(header absent)' : $status->meta->scopes->raw,
    'parsed as' => (string) $status->scopes,
]);

if ($status->scopes->isUnrestricted()) {
    $io->info('A star: this token holds every scope.');
} elseif (!$status->scopesAreKnown()) {
    $io->warn('No scopes were reported. Not the same as "no permissions" - the question was not answered.');
}

$io->line();

$wanted = ['domains:read_only', 'domains:read_write', 'account:read_only'];
$missing = $status->missing($wanted);

foreach ($wanted as $scope) {
    $io->line(sprintf('  %-22s %s', $scope, $status->allows($scope) ? 'yes' : 'no'));
}

$io->line();

if (in_array('domains:read_write', $missing, true)) {
    $io->warn('This token cannot change DNS. Reading zones will work; creating a record will be a 403.');
}

// The rate limit, measured on an authenticated request rather than assumed from the
// unauthenticated one.
$meta = $status->meta;

$io->values([
    // Measured on an authenticated request rather than assumed from the unauthenticated
    // reading of 1840, which need not be the same limit.
    'rate limit' => sprintf(
        '%s remaining of %s, resets %s',
        $meta->rateLimitRemaining ?? '?',
        $meta->rateLimit ?? '?',
        $meta->rateLimitResetsAt()?->format('H:i:s \U\T\C') ?? 'unknown'
    ),
    // Present on every response including this successful one, which is the trap.
    'Retry-After' => $meta->retryAfter === null
        ? '(absent)'
        : $meta->retryAfter . ' - on a 200, so it is not a throttle signal',
    'X-Spec-Version' => $meta->specVersion ?? '(absent)',
]);
$io->line();

// Grants: 204 for an unrestricted user, which is the trap this endpoint carries.
$grants = $linode->profile()->grants();

if ($grants === null) {
    $io->info('No grants: an unrestricted user, and Linode says so with a 204 rather than an object.');
} else {
    $domains = $grants->for('domain');

    $io->success('Grants (restricted user):');
    $io->values([
        'add domains' => $grants->canAddDomains() ? 'yes' : 'no',
        'account access' => $grants->accountAccess() ?? 'none',
        'zones visible' => (string) count($domains),
    ]);

    foreach (array_slice($domains, 0, 10) as $grant) {
        $io->line(sprintf('    %-34s %s', (string) $grant->label, $grant->permissions ?? 'no access'));
    }
}

$io->line();

$account = $linode->account()->find();

if ($account === null) {
    $io->info('The account is not readable by this token - it has no account:read_only, which is');
    $io->info('correct for a credential that only manages DNS.');

    exit(0);
}

$io->success('Account:');
$io->values([
    'name' => $account->name(),
    'euuid' => (string) $account->euuid,
    'active since' => $account->activeSince?->format('Y-m-d') ?? 'unknown',
    'balance' => number_format($account->balance ?? 0.0, 2),
]);
