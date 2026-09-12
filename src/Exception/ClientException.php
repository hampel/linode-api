<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

/**
 * A 4xx this package does not name specifically.
 *
 * The concrete types above cover every status Linode is documented to use, so reaching here
 * means the API has started using one they had not - which is worth knowing about rather than
 * having quietly absorbed into a broader class.
 */
final class ClientException extends ApiException
{
}
