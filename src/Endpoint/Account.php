<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Endpoint;

// Same name as the entity it returns - see the note on the Profile endpoint.
use Hampel\Linode\Api\Entity;
use Hampel\Linode\Api\Exception\NotPermittedException;

/**
 * The billing account behind the token.
 *
 * https://techdocs.akamai.com/linode-api/reference/get-account
 *
 * NEEDS `account:read_only`, WHICH IS NOT A GIVEN. A token scoped to manage DNS and nothing
 * else is refused here, and that is correct rather than a limitation to work around - an
 * integration that edits zones has no business reading a billing address. Use the Profile
 * endpoint to check a token; use this only when the account details are the thing you
 * actually want.
 *
 * THE REFUSAL IS A 401, NOT A 403 - measured against the live API on 12 September 2026 with
 * a token holding `domains:read_write` and nothing else. It still arrives here as
 * NotPermittedException, because the type follows the situation rather than the status; see
 * ApiException. A catch written against the status code would miss it.
 */
final class Account extends Endpoint
{
    /**
     * @throws NotPermittedException  when the token lacks `account:read_only` - a scope
     *         problem rather than a bad credential, despite arriving as a 401
     */
    public function get(): Entity\Account
    {
        return Entity\Account::fromArray($this->apiGet('account')->object());
    }

    /**
     * The account, or null when this token may not read it.
     *
     * For a diagnostic that wants to report as much as it can rather than stop at the first
     * thing it is not allowed to see. Only the 403 is absorbed; a bad token still raises.
     */
    public function find(): ?Entity\Account
    {
        try {
            return $this->get();
        } catch (NotPermittedException $e) {
            $this->logger->info('Linode account is not readable by this token', [
                'required' => (string) $e->requiredScopes(),
                'held' => (string) $e->heldScopes(),
            ]);

            return null;
        }
    }
}
