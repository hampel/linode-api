<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Exception;

/**
 * A 2xx that is well-formed and does not answer the question that was asked.
 *
 * Distinct from MalformedResponseException, which is a body that is not JSON at all. This one
 * decodes perfectly and is still wrong: the case it exists for is a filtered list whose rows do
 * not match the filter. `Domains::findByName()` filters on the `X-Filter` header, and a server,
 * proxy or gateway that stopped honouring that header would answer 200 with the whole
 * collection. Returning null there would tell the caller "no such zone", which is not what is
 * known - what is known is that the question went unanswered.
 *
 * It extends ApiException so an existing `catch (ApiException)` sees it.
 */
final class UnexpectedResponseException extends ApiException
{
}
