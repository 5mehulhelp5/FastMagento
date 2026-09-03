<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Search;

/**
 * Core's default query decorator: the body goes out exactly as assembled.
 */
class NullQueryDecorator implements QueryDecoratorInterface
{
    public function decorate(
        array $query,
        string $surface,
        string $target,
        ?int $storeId = null,
        ?int $customerId = null
    ): array {
        return $query;
    }
}
