<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Authentication;

use Psr\Http\Message\RequestInterface;

/**
 * How a request proves who it is.
 *
 * There is one implementation, because Linode has one wire format: `Authorization: Bearer
 * <token>`, used identically by a personal access token and by an OAuth access token. The
 * interface is here anyway, for the two things that will want to sit behind it - a token
 * that refreshes itself before it expires, and an application that resolves a per-tenant
 * credential at request time rather than at construction.
 */
interface Authentication
{
    public function applyTo(RequestInterface $request): RequestInterface;

    /**
     * What this credential is, for a log line or an exception message.
     *
     * MUST NOT INCLUDE THE TOKEN, or any part of it long enough to be useful. A credential
     * ends up in an error message far more often than anyone intends, and this method is
     * the reason a token does not.
     */
    public function describe(): string;
}
