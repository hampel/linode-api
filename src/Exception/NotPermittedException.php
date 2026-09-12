<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

use Hampel\Linode\Api\Result\Scopes;

/**
 * The credential is real and does not have the standing for this.
 *
 * IT ARRIVES AS A 401 MORE OFTEN THAN A 403, which is why this type exists rather than a
 * `$e->statusCode === 403` check at the call site. Measured on 12 September 2026: a valid
 * token calling an endpoint outside its scopes answers 401 with "Your OAuth token is not
 * authorized to use this endpoint." - the same status as a token that is not a token. See
 * ApiException for how the two are told apart, and why the type follows the situation rather
 * than the status.
 *
 * Two situations reach here, and both are configuration rather than code:
 *
 *   - the token was created without the scope the endpoint needs. `requiredScopes()` names
 *     what it wanted and `heldScopes()` what the token has; the fix is a new token, since
 *     Linode does not widen an existing one.
 *   - the token belongs to a restricted user whose grants do not cover the object. The
 *     Profile endpoint's grants() is where that is written down.
 *
 * `isScopeFailure()` separates them.
 */
final class NotPermittedException extends ApiException
{
    /**
     * Whether this was the token's scopes rather than a restricted user's grants.
     *
     * True when Linode reported the token's own scopes alongside the refusal, which it can
     * only do for a token it recognises.
     */
    public function isScopeFailure(): bool
    {
        return !$this->meta->scopes->isUnknown()
            && !$this->meta->acceptedScopes->isUnknown()
            && !$this->meta->scopes->allows((string) $this->meta->acceptedScopes);
    }

    /**
     * What the endpoint would have accepted, from `X-Accepted-OAuth-Scopes`.
     */
    public function requiredScopes(): Scopes
    {
        return $this->meta->acceptedScopes;
    }

    /**
     * What the token actually holds, from `X-OAuth-Scopes`.
     */
    public function heldScopes(): Scopes
    {
        return $this->meta->scopes;
    }
}
