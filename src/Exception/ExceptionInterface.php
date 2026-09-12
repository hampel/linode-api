<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

/**
 * Every exception this package throws implements this, so a consumer can catch
 * "something went wrong in the Linode client" without naming a hierarchy.
 */
interface ExceptionInterface extends \Throwable
{
}
