<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Enum;

/**
 * Which kind of CAA statement a record makes. Meaningful on a CAA record and on no other.
 */
enum CaaTag: string
{
    /** This authority may issue certificates for the domain. */
    case Issue = 'issue';

    /** This authority may issue wildcard certificates for the domain. */
    case IssueWild = 'issuewild';

    /** Where to report a request this policy would have refused - a mailto: or https: URL. */
    case Iodef = 'iodef';
}
