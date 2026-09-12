<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

/**
 * No such thing - HTTP 404.
 *
 * Raised for a record that is not there and for a path the API does not have, which Linode
 * does not distinguish: both answer `{"errors": [{"reason": "Not found"}]}`. The
 * endpoint helpers that treat absence as an ordinary answer catch this and return null
 * instead.
 */
final class NotFoundException extends ApiException
{
}
