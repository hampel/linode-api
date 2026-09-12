<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Enum;

/**
 * Whether the zone is being served.
 *
 * A `disabled` domain keeps all of its records and answers none of them, which makes it the
 * safe way to take a zone out of service without losing the work of rebuilding it.
 */
enum DomainStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
