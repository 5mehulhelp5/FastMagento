<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Search;

/**
 * The last step before an OpenSearch query body is issued: a chance to decorate it for the
 * current shopper. Core's default (NullQueryDecorator) returns the body untouched; the
 * a companion module may swap in its own decorator through a DI preference.
 *
 */
interface QueryDecoratorInterface
{
    /**
     * @param array<string, mixed> $query the assembled OpenSearch query body
     * @param string $surface which storefront surface is asking (search, listing, recommendations…)
     * @param string $target which index the query ranks against
     * @return array<string, mixed> the body to issue — the input itself when nothing applies
     */
    public function decorate(
        array $query,
        string $surface,
        string $target,
        ?int $storeId = null,
        ?int $customerId = null
    ): array;
}
