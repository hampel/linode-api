<?php

/**
 * Exercise: read the zones on the account, and one in detail. Read-only.
 *
 * READ-ONLY throughout. It lists, filters by name, walks the records and prints the zone
 * file Linode actually serves - which is the only place the generated SOA and NS records
 * appear, since the record endpoint does not represent them.
 *
 * Needs LINODE_TOKEN and `domains:read_only`. LINODE_DOMAIN names the zone to look at in
 * detail; without it the exercise stops after the list.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\Linode\Api\Entity\DomainRecord;
use Hampel\Linode\Api\Exception\ExceptionInterface;
use Hampel\Linode\Api\Support\Filter;

require __DIR__ . '/lib/client.php';

$io->title('linode · domains');

$linode = harness_client($io);

try {
    // Ordered, because an unordered walk has no promise of stability across pages - see
    // Endpoint::apiEach().
    $zones = $linode->domains()->all(Filter::make()->orderBy('domain'));
} catch (ExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());
    $io->info('A 403 here means the token has no domains scope.');

    exit(1);
}

$io->success(sprintf('✓ %d zone(s)', count($zones)));
$io->line();

foreach ($zones as $zone) {
    $io->line(sprintf(
        '  %-34s %-7s %-9s ttl %-8s %s',
        $zone->domain,
        $zone->type->value ?? '(' . (string) ($zone->raw['type'] ?? '?') . ')',
        $zone->status?->value ?? '?',
        $zone->effectiveTtl(),
        $zone->tags === [] ? '' : '[' . implode(', ', $zone->tags) . ']'
    ));
}

$io->line();

$name = getenv('LINODE_DOMAIN');

if (!is_string($name) || $name === '') {
    $io->info('Set LINODE_DOMAIN to read one zone in detail.');

    exit(0);
}

// The lookup that matters on a real account: the name is what you have, the id is what the
// API wants, and the bridge is a header rather than a query parameter.
$zone = $linode->domains()->findByName($name);

if ($zone === null) {
    $io->warn(sprintf('No zone called %s on this account.', $name));

    exit(0);
}

$io->success(sprintf('✓ %s is id %d', $zone->domain, (int) $zone->id));
$io->value('soa email', (string) $zone->soaEmail);
$io->value('axfr', $zone->axfrIps === [] ? 'nobody may transfer this zone out' : implode(', ', $zone->axfrIps));
$io->line();

$records = $linode->domains()->records((int) $zone->id);

foreach ($records->all(Filter::make()->orderBy('name')) as $record) {
    $io->line(sprintf(
        '  %-6s %-28s %-40s %s',
        $record->typeName() ?? '?',
        $record->fqdn($zone->domain),
        (string) $record->target,
        $record->type?->usesPriority() === true ? 'priority ' . (string) $record->priority : ''
    ));
}

$io->line();

// SOA and NS are generated and are not records, so a zone with a short list here can still
// be serving perfectly well. The zone file is the whole picture.
$io->success('Zone file as Linode serves it:');

foreach ($linode->domains()->zoneFile((int) $zone->id) as $line) {
    $io->line('  ' . $line);
}

$io->line();

$mx = $records->ofType(\Hampel\Linode\Api\Enum\RecordType::MX);

if ($mx === []) {
    $io->info('No MX records: this zone accepts no mail, and does not say so explicitly either.');
} else {
    $io->value('mail exchangers', (string) count($mx));

    foreach ($mx as $record) {
        if ($record->target === '') {
            $io->warn('  A null MX - RFC 7505. This zone declares that it accepts no mail.');
        }
    }
}

$io->line();
$io->info(sprintf('Records were read with %s.', DomainRecord::class));
