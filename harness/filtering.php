<?php

/**
 * Exercise: is Linode still honouring X-Filter? Read-only.
 *
 * This one exists for a failure that cannot be caught by a test and does not look like a
 * failure when it happens.
 *
 * `Domains::findByName()` and every `ofType()` / `named()` lookup rest on the server reading
 * the `X-Filter` header. If it ever stopped - a change at Linode's end, a proxy stripping an
 * unfamiliar header, a WAF - nothing would error. The request would answer 200 with the
 * WHOLE collection, and a caller taking the first row would get an arbitrary zone while
 * believing it had the one it asked for. Every edit after that goes to somebody else's DNS.
 *
 * A mocked test cannot see this, because the mock honours the filter by construction: it
 * returns whatever the fixture says. Only a real call can answer it.
 *
 * So each probe below issues the same request twice, filtered and unfiltered, and prints the
 * two counts against each other. Equal counts on a filter that cannot match anything is the
 * dangerous outcome, and the numbers are there for a person to notice.
 *
 * Needs LINODE_TOKEN and `domains:read_only`. LINODE_DOMAIN, if set, adds the record-level
 * probes against that zone.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\Linode\Api\Exception\ExceptionInterface;
use Hampel\Linode\Api\Support\Filter;

require __DIR__ . '/lib/client.php';

$io->title('linode · is the filter honoured?');

$linode = harness_client($io);

// A name nothing can hold. If the filter is read we expect zero; if it is ignored we get the
// unfiltered count, which is the outcome worth catching because nothing about it errors.
$needle = 'zzz-no-such-zone-' . bin2hex(random_bytes(4)) . '.invalid';

try {
    // Each probe fetches its own baseline rather than reusing a number printed earlier. When
    // the two agree that agreement is itself a check - it says both requests looked at the
    // same collection, which is the assumption the comparison rests on.
    $unfiltered = $linode->domains()->list()->total;
    $filtered = $linode->domains()->list(1, Filter::where('domain', $needle))->total;
} catch (ExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());

    exit(1);
}

$io->values([
    'zones, unfiltered' => (string) $unfiltered,
    'zones, filtered to a name that cannot exist' => (string) $filtered,
]);
$io->line();

$verdict = 0;

if ($filtered === 0) {
    $io->success('✓ the domain filter is honoured');
} elseif ($filtered === $unfiltered) {
    $io->error('✗ THE FILTER IS BEING IGNORED - the filtered count is the whole collection.');
    $io->error('  findByName() would be returning an arbitrary zone if it did not re-check the');
    $io->error('  name it got back. Do not trust any filtered lookup until this is understood.');
    $verdict = 1;
} else {
    $io->warn(sprintf('? %d zones matched a name that cannot exist. Neither honoured nor ignored.', $filtered));
    $verdict = 1;
}

$io->line();

// The other half: the client-side re-check in findByName(). Asking for a name that is not on
// the account must answer null whatever the filter did.
$phantom = $linode->domains()->findByName($needle);

if ($phantom === null) {
    $io->success('✓ findByName() on a name that is not there is null');
} else {
    $io->error(sprintf('✗ findByName() invented a zone: %s', $phantom->domain));
    $verdict = 1;
}

$io->line();

$name = getenv('LINODE_DOMAIN');

if (!is_string($name) || $name === '') {
    $io->info('Set LINODE_DOMAIN to probe the record filters too.');

    exit($verdict);
}

$zone = $linode->domains()->findByName($name);

if ($zone === null) {
    $io->warn(sprintf('No zone called %s on this account; skipping the record probes.', $name));

    exit($verdict);
}

$records = $linode->domains()->records((int) $zone->id);

$allRecords = count($records->all());
$noSuchName = count($records->named('zzz-no-such-record-' . bin2hex(random_bytes(4))));

$io->values([
    'records in ' . $zone->domain => (string) $allRecords,
    'records with a name that cannot exist' => (string) $noSuchName,
]);

if ($noSuchName === 0) {
    $io->success('✓ the record name filter is honoured');
} else {
    $io->error('✗ the record name filter is NOT being honoured');
    $verdict = 1;
}

$io->line();

// A filter that SHOULD match, so a zero above is not simply "filters always return nothing".
// Without this the probe cannot tell a working filter from a broken endpoint.
$aRecords = count($records->ofType(\Hampel\Linode\Api\Enum\RecordType::A));

$io->values([
    'records, all types' => (string) $allRecords,
    'records, type=A' => (string) $aRecords,
]);

if ($allRecords > 0 && $aRecords === $allRecords) {
    $io->info('Equal - which is fine if every record in this zone is an A record, and');
    $io->info('otherwise means the type filter was ignored. Check the zone before concluding.');
}

exit($verdict);
