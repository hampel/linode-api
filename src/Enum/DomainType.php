<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Enum;

/**
 * Whether Linode is the authority for this zone or a copy of one.
 */
enum DomainType: string
{
    /**
     * Linode holds the zone and answers for it. Needs `soa_email`.
     */
    case Master = 'master';

    /**
     * Linode copies the zone from somewhere else by AXFR. Needs at least one `master_ips`
     * entry, and the records are not yours to edit - they arrive with the transfer.
     */
    case Slave = 'slave';
}
