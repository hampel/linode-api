<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests\Fixture;

use Hampel\Linode\Api\Endpoint\Endpoint;

/**
 * A worked example of the extension point, and the assertion that it works.
 *
 * This is what a consumer writes for one of the three hundred endpoints the package does
 * not wrap - here `GET /v4/linode/instances`. It inherits the pagination, because every
 * collection on this API answers in the same envelope, and it needs no registration: the
 * class IS the registration.
 *
 *     $linode->endpoint(Instances::class)->labels();
 */
final class Instances extends Endpoint
{
    /**
     * @return list<string>
     */
    public function labels(): array
    {
        $labels = [];

        foreach ($this->apiEach('linode/instances', static fn (array $row): mixed => $row['label'] ?? null) as $label) {
            if (is_string($label)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }
}
