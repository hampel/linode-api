<?php

/**
 * Exercise: WRITES TO REAL DNS - a throwaway TXT record, created and then deleted.
 *
 * It creates a record in a nominated zone, reads it back, updates it, and deletes it.
 *
 * This is the only exercise here that changes anything, and it changes something public: a
 * record created in a live zone is served by Linode's nameservers to the whole internet for
 * as long as it exists. It is written to be safe to run against a zone you own - the record
 * is a TXT under a name nothing resolves against, it is deleted in a `finally`, and a failed
 * cleanup is reported loudly with the id to remove by hand - but "safe" here means "does not
 * break the zone", not "invisible".
 *
 * WHAT IT SETTLES, none of which a mocked suite can:
 *
 *   - whether Linode accepts the create payload this package builds, field for field;
 *   - whether an update really is partial, as the API's PUT semantics claim: it changes the
 *     TTL alone and checks the target survived;
 *   - what Linode stores for a TTL that is not on its list - the specification says a record
 *     rounds to the NEAREST accepted value off a list starting at 300, which is different
 *     from the zone fields' round-up, and this is the only way to know it is still true;
 *   - whether a record is readable immediately after the create returns.
 *
 * TO RUN IT:
 *
 *     LINODE_DOMAIN=example.com LINODE_WRITE_RECORDS=yes vendor/bin/rig records
 *
 * `yes` rather than `1`, so it cannot be set by habit alongside an ordinary opt-in. Under an
 * agent it refuses even then, unless LINODE_AGENT_MAY_WRITE_RECORDS=1 is also given on the
 * command line for that one run - never in .env, because a persisted authorisation is one
 * nobody gave.
 *
 * Needs LINODE_TOKEN with `domains:read_write`, and LINODE_DOMAIN.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\Linode\Api\Entity\DomainRecord;
use Hampel\Linode\Api\Exception\ExceptionInterface;
use Hampel\Linode\Api\Support\Ttl;

require __DIR__ . '/lib/agent.php';
require __DIR__ . '/lib/client.php';

$io->title('linode · domain records (writes)');

$linode = harness_client($io);
$name = harness_domain($io);

// Layer 2: the harmless thing is the default, and the mode is printed above the work rather
// than after it - a run that did not write proves none of what a run that did would.
$permitted = getenv('LINODE_WRITE_RECORDS') === 'yes';

// Layer 3: the opt-in above lives in the .env of whoever owns the token, and it generally
// says yes, because that is how they run their own exercises. An agent inherits an
// authorisation nobody gave it. CLAUDECODE is a fact about who is running the command, which
// a stale .env cannot fake.
if ($permitted && harness_agent_refuses('LINODE_AGENT_MAY_WRITE_RECORDS')) {
    $permitted = false;
    $io->warn('mode: read-only - LINODE_WRITE_RECORDS is ignored in an agent session');
    $io->warn('Set LINODE_AGENT_MAY_WRITE_RECORDS=1 on the command line for a single run, if asked to.');
} elseif ($permitted) {
    $io->warn('mode: WRITING - a record will be created in real DNS and then deleted');
} else {
    $io->info('mode: read-only - nothing will be created. This run answers none of the questions');
    $io->info('in the docblock; set LINODE_WRITE_RECORDS=yes to have it answer them.');
}

$io->line();

$zone = $linode->domains()->findByName($name);

if ($zone === null) {
    $io->error(sprintf('No zone called %s on this account.', $name));

    exit(1);
}

if (!$zone->isMaster()) {
    $io->error(sprintf('%s is a slave zone: its records come from the transfer and cannot be written.', $zone->domain));

    exit(1);
}

$io->values([
    'zone' => $zone->domain,
    'id' => (string) $zone->id,
    'existing records' => (string) count($linode->domains()->records((int) $zone->id)->all()),
]);
$io->line();

if (!$permitted) {
    exit(0);
}

$records = $linode->domains()->records((int) $zone->id);

// Read before writing. The zone TTL probe below changes a real zone's setting, so what it was
// is captured here rather than assumed, and put back in the finally.
$zoneTtlBefore = $zone->ttlSec ?? 0;

// Named so that a failed cleanup is unmistakable in Linode's own DNS manager, and under a
// label nothing resolves against.
$label = 'zz-delete-me-linode-api-harness-' . date('Ymd-His');
$probe = null;
$failure = null;
$leaked = false;

// The TTL rule is mapped rather than spot-checked, because one value cannot settle it.
//
// The specification says a record's ttl_sec is "rounded to the NEAREST valid value" off a list
// starting at 300, while a zone's four interval fields round UP off a list starting at 30.
// Measured on 12 September 2026, 900 came back as 3600 - so it rounds up, not to the nearest,
// and the documentation is wrong about that. But 900 rounds up to 3600 under BOTH candidate
// lists, so it says nothing about where the list starts.
//
// These do. 60 and 120 are the discriminators:
//
//   asked   if the list starts at 300   if it is the zone list (30, 120, 300, ...)
//   60      300                         120
//   120     300                         120
//
// One record is created and then updated through each value, rather than one record per
// value: it is the same measurement with a single object to clean up, and it exercises the
// update path as many times as it exercises the rounding.
$askedTtl = 900;

$ttlProbes = [0, 1, 30, 60, 120, 300, 3000, 86401, 2419201];

/**
 * The TTL the zone file actually renders for our probe record, or null when the file does not
 * mention it yet.
 *
 * THE ZONE FILE IS NOT LIVE, AND THE LAG IS MINUTES. Measured on 12 September 2026: straight
 * after a write it showed a TTL two changes old and a record that had already been deleted,
 * and on another run a change had still not rendered after 160 seconds. So anything read from
 * it has to be waited for rather than taken on the first look, and a probe that breaks on the
 * first sight of its own label reads the previous state and calls it a result. This one did
 * exactly that before it was fixed.
 *
 * @param  callable(): list<string>  $zoneFile
 */
$renderedTtl = static function (callable $zoneFile) use ($label): ?int {
    foreach ($zoneFile() as $line) {
        if (str_contains($line, $label) && preg_match('/\s(\d+)\s+TXT\s/', $line, $m) === 1) {
            return (int) $m[1];
        }
    }

    return null;
};

/**
 * Poll until the rendered TTL is present and is not $notThis, so convergence is observed
 * rather than assumed.
 *
 * 300s of headroom. 150 was not enough on one run - the file had not moved after 160s - and a
 * timeout here reads as a finding about the API when it is really a finding about this loop,
 * so the ceiling is set well past anything observed rather than just past it.
 *
 * @param  callable(): ?int  $read
 * @return array{int|null, int}  the value, and how long it took
 */
$waitFor = static function (callable $read, ?int $notThis): array {
    $waited = 0;

    while ($waited <= 300) {
        $value = $read();

        if ($value !== null && $value !== $notThis) {
            return [$value, $waited];
        }

        sleep(10);
        $waited += 10;
    }

    return [null, $waited];
};

try {
    $probe = $records->create(
        DomainRecord::txt($label, 'created by the hampel/linode-api harness; safe to delete')
            ->withTtl($askedTtl)
    );

    $io->success(sprintf('✓ created record %d', (int) $probe->id));

    $io->values([
        'name' => (string) $probe->name,
        'fqdn' => $probe->fqdn($zone->domain),
        'ttl asked for' => (string) $askedTtl,
        'ttl stored' => (string) $probe->ttlSec,
        'ttl this package predicted' => (string) Ttl::round($askedTtl),
    ]);

    if ($probe->ttlSec !== Ttl::round($askedTtl)) {
        $io->warn(sprintf(
            '✗ stored %s, predicted %s - the rounding rule in Support\Ttl is wrong or has changed.',
            (string) $probe->ttlSec,
            (string) Ttl::round($askedTtl)
        ));
    } else {
        $io->success('✓ the TTL rounding rule still holds');
    }

    $io->line();

    // Readable straight away, or not? Worth knowing before writing anything that reads back
    // what it just wrote. Note this is the RECORD endpoint - the zone file is a different
    // story entirely, and the probe further down is about that.
    if ($records->find((int) $probe->id) === null) {
        $io->warn('? the record was not readable immediately after the create returned');
    } else {
        $io->success('✓ readable immediately from the record endpoint');
    }

    $io->line();

    // Is the PUT really partial? Change the TTL alone and see whether the target survives.
    $before = (string) $probe->target;
    $updated = $records->update((int) $probe->id, ['ttl_sec' => 3600]);

    $io->values([
        'target before the update' => $before,
        'target after a ttl-only update' => (string) $updated->target,
        'ttl after' => (string) $updated->ttlSec,
    ]);

    if ((string) $updated->target === $before) {
        $io->success('✓ the update is partial - a PUT of one field left the rest alone');
    } else {
        $io->error('✗ the PUT was NOT partial: the target changed when only the TTL was sent.');
        $io->error('  Connection::put() documents the opposite, and every update in this package');
        $io->error('  relies on it. This needs fixing before anything else.');
    }

    $io->line();

    // A RECORD'S ttl_sec OF 0 INHERITS THE ZONE'S TTL. Settled on 2026-09-13 by moving a live
    // zone from 3600 to 7200 and watching its zero-TTL records follow within 30 seconds, read
    // off the authoritative nameserver; a fixed 86400 would not have moved. This is the
    // regression check for that, and it is cheap because it asks DNS rather than the API.
    //
    // THE ZONE FILE IS THE WRONG INSTRUMENT FOR ANYTHING SERVED. It is regenerated
    // asynchronously and was measured lagging by minutes - once still stale after 160 seconds -
    // where dig against ns1 reflected the same change in 30. An earlier version of this probe
    // waited on the zone file and reported itself inconclusive on every run.
    $records->update((int) $probe->id, ['ttl_sec' => 0]);
    $zoneTtlNow = $linode->domains()->get((int) $zone->id)->ttlSec ?? 0;
    $expected = Ttl::effective('ttl_sec', $zoneTtlNow);

    $io->success('What a record ttl_sec of 0 inherits:');
    $io->values([
        "the zone's own ttl_sec" => (string) $zoneTtlNow,
        'so the record should serve' => (string) $expected,
        'if it were a fixed default instead' => (string) Ttl::DEFAULT_TTL,
    ]);

    if (trim((string) shell_exec('command -v dig')) === '') {
        $io->warn('? dig is not installed, so this cannot be checked. Not a finding about the API.');
    } else {
        $fqdn = $probe->fqdn($zone->domain);
        $served = null;
        $waited = 0;

        while ($waited <= 180) {
            $out = [];
            exec(sprintf('dig +tries=1 +time=3 @ns1.linode.com %s TXT +noall +answer 2>/dev/null',
                escapeshellarg($fqdn)), $out);

            foreach ($out as $line) {
                if (preg_match('/^\S+\s+(\d+)\s+IN\s+TXT\s/', $line, $m) === 1) {
                    $served = (int) $m[1];
                    break 2;
                }
            }

            sleep(10);
            $waited += 10;
        }

        if ($served === null) {
            $io->warn(sprintf('? the nameserver was not serving %s after %ds - inconclusive.', $fqdn, $waited));
        } else {
            $io->values(['served TTL (dig @ns1)' => (string) $served, 'after' => $waited . 's']);

            if ($served === $expected) {
                $io->success("✓ a record ttl_sec of 0 still inherits the zone's TTL");
            } elseif ($served === Ttl::DEFAULT_TTL && $expected !== Ttl::DEFAULT_TTL) {
                $io->error('✗ it served the fixed default, not the zone - the inheritance rule has changed.');
            } else {
                $io->warn(sprintf('? served %d, expected %d - neither rule explains it.', $served, $expected));
            }
        }
    }

    $io->line();

    // The rounding rule, mapped across the range rather than spot-checked, for a record and
    // then for the zone. Each line is asked -> stored (predicted); a disagreement column is
    // the finding. This is what corrected Support\Ttl in the first place.
    $io->success('Record TTL rounding, asked -> stored (predicted):');

    $wrong = 0;

    foreach ($ttlProbes as $asked) {
        $stored = $records->update((int) $probe->id, ['ttl_sec' => $asked])->ttlSec;
        $predicted = Ttl::round($asked);

        if ($stored !== $predicted) {
            $wrong++;
        }

        $io->line(sprintf(
            '    %-8s -> %-8s (%s) %s',
            (string) $asked,
            (string) $stored,
            (string) $predicted,
            $stored === $predicted ? '' : '  <- disagrees'
        ));
    }

    $io->line($wrong > 0
        ? sprintf('  ✗ %d of %d disagree - Support\Ttl has the record rule wrong.', $wrong, count($ttlProbes))
        : '  ✓ Support\Ttl predicts every one of them');

    $io->line();

    // The same question at the zone level. This one touches the real zone object rather than
    // a throwaway, so it runs last and the finally puts the original back.
    $io->success('Zone TTL rounding, asked -> stored (predicted):');

    $zoneWrong = 0;

    foreach ([60, 120, 900, 86401] as $asked) {
        $stored = $linode->domains()->update((int) $zone->id, ['ttl_sec' => $asked])->ttlSec;
        $predicted = Ttl::round($asked);

        if ($stored !== $predicted) {
            $zoneWrong++;
        }

        $io->line(sprintf(
            '    %-8s -> %-8s (%s) %s',
            (string) $asked,
            (string) $stored,
            (string) $predicted,
            $stored === $predicted ? '' : '  <- disagrees'
        ));
    }

    $io->line($zoneWrong > 0
        ? sprintf('  ✗ %d of 4 disagree - Support\Ttl has the zone rule wrong too.', $zoneWrong)
        : '  ✓ Support\Ttl predicts the zone rule as well');

} catch (ExceptionInterface $e) {
    $failure = $e;
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());
} finally {
    // PHP does not run a finally on exit(), so every exit in this exercise is outside the
    // block - otherwise a successful run would be the one that skipped its own cleanup.
    try {
        $restored = $linode->domains()->update((int) $zone->id, ['ttl_sec' => $zoneTtlBefore])->ttlSec;

        if ($restored !== $zoneTtlBefore) {
            $leaked = true;
            $io->error(sprintf(
                '✗ the zone TTL was %s and is now %s - put it back by hand.',
                (string) $zoneTtlBefore,
                (string) $restored
            ));
        }
    } catch (ExceptionInterface $cleanup) {
        $leaked = true;
        $io->error('✗ COULD NOT RESTORE THE ZONE TTL - ' . $cleanup::class);
        $io->error(sprintf('Set %s back to ttl_sec %s by hand.', $zone->domain, (string) $zoneTtlBefore));
    }

    if ($probe !== null && $probe->id !== null) {
        try {
            $records->delete($probe->id);
            $io->line();
            $io->success(sprintf('✓ deleted record %d', $probe->id));
        } catch (ExceptionInterface $cleanup) {
            $leaked = true;
            $io->error('✗ CLEANUP FAILED - ' . $cleanup::class);
            $io->error(sprintf('Remove record %d (%s) from %s by hand.', $probe->id, $label, $zone->domain));
        }
    }
}

if ($failure !== null || $leaked) {
    exit(1);
}

$io->line();
$io->info('The zone is back as it was.');
