<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Endpoint;

// The entity and this endpoint share a name, which is Linode's naming rather than a
// collision worth renaming around: the resource is called Profile and so is the thing it
// returns. The namespace import keeps both readable without inventing a second word for one
// of them.
use Hampel\Linode\Api\Entity;
use Hampel\Linode\Api\Result\TokenStatus;

/**
 * The user the token belongs to - and the cheapest way to find out whether it works.
 *
 * https://techdocs.akamai.com/linode-api/reference/get-profile
 *
 * NEEDS NO OAUTH SCOPE. That is what makes it the right endpoint for a token check: every
 * other endpoint conflates "your token is wrong" with "your token may not do this", and this
 * one cannot, because there is nothing here to be refused for.
 */
final class Profile extends Endpoint
{
    /**
     * Who the token belongs to.
     */
    public function get(): Entity\Profile
    {
        return Entity\Profile::fromArray($this->apiGet('profile')->object());
    }

    /**
     * Check the credential, and find out what it may do, in one request.
     *
     * Raises NotAuthenticatedException when the token is not valid - see TokenStatus for why
     * that is a throw rather than a flag on the returned object.
     */
    public function verify(): TokenStatus
    {
        $response = $this->apiGet('profile');
        $profile = Entity\Profile::fromArray($response->object());

        return new TokenStatus($profile, $response->meta->scopes, $response->meta);
    }

    /**
     * What a restricted user is allowed to do, object by object.
     *
     * NULL MEANS UNRESTRICTED, NOT "NO PERMISSIONS". Linode answers `204 No Content` here for
     * a user who has no grants because they need none, and a 204 decodes to an empty body -
     * which as a Grants object would say the user may do nothing at all, the exact opposite
     * of the truth. So the empty answer is returned as null and `Profile::$restricted` is
     * what to branch on:
     *
     *     $profile = $linode->profile()->get();
     *     $grants  = $profile->restricted ? $linode->profile()->grants() : null;
     */
    public function grants(): ?Entity\Grants
    {
        $response = $this->apiGet('profile/grants');

        if ($response->status === 204 || $response->isEmpty()) {
            return null;
        }

        return Entity\Grants::fromArray($response->object());
    }
}
