<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Hampel\Linode\Api\Exception\NotAuthenticatedException;

final class ProfileTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function profileRow(bool $restricted = false): array
    {
        return [
            'uid' => 1234,
            'username' => 'exampleuser',
            'email' => 'ops@example.com',
            'restricted' => $restricted,
            'two_factor_auth' => true,
            'timezone' => 'Australia/Sydney',
            'authentication_type' => 'password',
            'ip_whitelist_enabled' => false,
            'verified_phone_number' => null,
            'authorized_keys' => [],
            'email_notifications' => true,
            'lish_auth_method' => 'keys_only',
            'referrals' => [],
        ];
    }

    public function test_it_reads_the_user_the_token_belongs_to(): void
    {
        $this->client->pushJson(200, $this->profileRow());

        $profile = $this->linode()->profile()->get();

        $this->assertSame('exampleuser', $profile->username);
        $this->assertSame('ops@example.com', $profile->email);
        $this->assertSame(1234, $profile->uid);
        $this->assertFalse($profile->isRestricted());
        $this->assertTrue($profile->twoFactorAuth);
        $this->assertSame('/v4/profile', $this->sentPath());
    }

    /**
     * The whole point of verifying against /profile: it needs no scope, so a success means
     * the credential itself is good, and the scopes come back in the headers for free.
     */
    public function test_verify_answers_who_the_token_is_and_what_it_may_do_in_one_request(): void
    {
        $this->client->pushJson(200, $this->profileRow(), [
            'X-OAuth-Scopes' => 'domains:read_write account:read_only',
        ]);

        $status = $this->linode()->verify();

        $this->assertSame('exampleuser', $status->username());
        $this->assertFalse($status->isRestricted());
        $this->assertTrue($status->scopesAreKnown());
        $this->assertTrue($status->allows('domains:read_write'));
        $this->assertTrue($status->allows('domains:read_only'), 'read_write covers read_only');
        $this->assertFalse($status->allows('linodes:read_only'));
        $this->assertSame([], $status->missing(['domains:read_write', 'account:read_only']));
        $this->assertSame(['linodes:read_write'], $status->missing(['domains:read_only', 'linodes:read_write']));
        $this->assertCount(1, $this->client->requests);
    }

    public function test_an_unrestricted_personal_access_token_reports_a_star(): void
    {
        $this->client->pushJson(200, $this->profileRow(), ['X-OAuth-Scopes' => '*']);

        $status = $this->linode()->verify();

        $this->assertTrue($status->scopes->isUnrestricted());
        $this->assertTrue($status->allows('domains:read_write'));
        $this->assertSame([], $status->missing(['anything:read_write']));
    }

    /**
     * A bad token raises rather than returning a status object saying it is bad - see
     * TokenStatus for why.
     */
    public function test_a_token_that_does_not_work_raises(): void
    {
        $this->client->pushJson(401, $this->errors([['reason' => 'Invalid Token']]));

        $this->expectException(NotAuthenticatedException::class);
        $this->linode()->verify();
    }

    /**
     * A stripped or absent header is not evidence that the token can do nothing, and must
     * not read as such - it means the question was not answered.
     */
    public function test_scopes_that_could_not_be_read_are_unknown_rather_than_empty(): void
    {
        $this->client->pushJson(200, $this->profileRow());

        $status = $this->linode()->verify();

        $this->assertFalse($status->scopesAreKnown());
        $this->assertFalse($status->allows('domains:read_only'));
        $this->assertSame('unknown', (string) $status->scopes);
    }

    public function test_the_summary_names_the_account_and_carries_no_token(): void
    {
        $this->client->pushJson(200, $this->profileRow(), ['X-OAuth-Scopes' => 'domains:read_write']);

        $summary = $this->linode()->verify()->summary();

        $this->assertStringContainsString('exampleuser', $summary);
        $this->assertStringContainsString('unrestricted', $summary);
        $this->assertStringContainsString('domains:read_write', $summary);
        $this->assertStringNotContainsString('test-token', $summary);
    }

    /**
     * The trap this endpoint carries: 204 for an unrestricted user. Decoded as an ordinary
     * body that is an empty grants object, which would say the user may do nothing.
     */
    public function test_an_unrestricted_user_has_no_grants_and_says_so_with_a_204(): void
    {
        $this->client->pushRaw(204, '');

        $this->assertNull($this->linode()->profile()->grants());
        $this->assertSame('/v4/profile/grants', $this->sentPath());
    }

    public function test_a_restricted_users_grants_are_read_globally_and_per_object(): void
    {
        $this->client->pushJson(200, [
            'global' => [
                'add_domains' => true,
                'add_linodes' => false,
                'account_access' => 'read_only',
                'cancel_account' => false,
            ],
            'domain' => [
                ['id' => 1234, 'label' => 'example.com', 'permissions' => 'read_write'],
                ['id' => 1235, 'label' => 'example.net', 'permissions' => 'read_only'],
                ['id' => 1236, 'label' => 'example.org', 'permissions' => null],
            ],
            'linode' => [],
        ]);

        $grants = $this->linode()->profile()->grants();

        $this->assertNotNull($grants);
        $this->assertTrue($grants->canAddDomains());
        $this->assertSame('read_only', $grants->accountAccess());
        $this->assertFalse($grants->global('cancel_account'));

        $this->assertTrue($grants->canWriteDomain(1234));
        $this->assertTrue($grants->canReadDomain(1234));

        $this->assertFalse($grants->canWriteDomain(1235));
        $this->assertTrue($grants->canReadDomain(1235));

        // permissions: null means no access at all
        $this->assertFalse($grants->canReadDomain(1236));

        // a zone the user cannot see is absent from the list entirely
        $this->assertNull($grants->find('domain', 9999));
        $this->assertFalse($grants->canReadDomain(9999));

        $this->assertCount(3, $grants->for('domain'));
        $this->assertSame('example.com', $grants->for('domain')[0]->label);
        $this->assertSame([], $grants->for('volume'));
    }

}
