<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Hampel\Linode\Api\Exception\NotAuthenticatedException;
use Hampel\Linode\Api\Exception\NotPermittedException;

final class AccountTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'email' => 'billing@example.com',
            'company' => 'Example Pty Ltd',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'euuid' => 'E1AF5EEC-0000-0000-0000-000000000000',
            'balance' => 12.34,
            'balance_uninvoiced' => 5.0,
            'billing_source' => 'akamai',
            'country' => 'AU',
            'city' => 'Sydney',
            'state' => 'NSW',
            'zip' => '2000',
            'active_since' => '2018-01-01T00:01:01',
            'capabilities' => ['Linodes', 'Object Storage', 'Kubernetes'],
        ];
    }

    public function test_it_reads_the_account(): void
    {
        $this->client->pushJson(200, $this->row());

        $account = $this->linode()->account()->get();

        $this->assertSame('billing@example.com', $account->email);
        $this->assertSame('Example Pty Ltd', $account->name());
        $this->assertSame(12.34, $account->balance);
        $this->assertSame(1234, $account->balanceCents());
        $this->assertTrue($account->owesMoney());
        $this->assertTrue($account->can('Object Storage'));
        $this->assertFalse($account->can('object_storage'), 'the strings are Linode\'s own');
        $this->assertSame('2018-01-01T00:01:01+00:00', $account->activeSince?->format('c'));
        $this->assertSame('/v4/account', $this->sentPath());
    }

    public function test_the_name_falls_back_to_the_person_when_there_is_no_company(): void
    {
        $row = $this->row();
        $row['company'] = '';

        $this->client->pushJson(200, $row);

        $this->assertSame('Ada Lovelace', $this->linode()->account()->get()->name());
    }

    public function test_a_credit_is_not_money_owed(): void
    {
        $row = $this->row();
        $row['balance'] = -20.0;

        $this->client->pushJson(200, $row);
        $account = $this->linode()->account()->get();

        $this->assertFalse($account->owesMoney());
        $this->assertSame(-2000, $account->balanceCents());
    }

    /**
     * A DNS token will not usually carry account:read_only, and that refusal is correct
     * rather than something to work around.
     */
    public function test_a_token_without_the_account_scope_raises_from_get(): void
    {
        $this->client->pushJson(403, $this->errors([['reason' => 'Your OAuth token is not authorized to use this endpoint.']]));

        $this->expectException(NotPermittedException::class);
        $this->linode()->account()->get();
    }

    public function test_find_absorbs_that_refusal_for_a_diagnostic_that_reports_what_it_can(): void
    {
        $this->client->pushJson(403, $this->errors([['reason' => 'Your OAuth token is not authorized to use this endpoint.']]));

        $this->assertNull($this->linode()->account()->find());
    }

    public function test_find_does_not_absorb_a_bad_token(): void
    {
        $this->client->pushJson(401, $this->errors([['reason' => 'Invalid Token']]));

        $this->expectException(NotAuthenticatedException::class);
        $this->linode()->account()->find();
    }
}
