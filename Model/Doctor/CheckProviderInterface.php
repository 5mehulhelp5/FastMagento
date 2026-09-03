<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Doctor;

/**
 * A section of `fastmagento:doctor` supplied by another module.
 *
 * Implementations are registered into Diagnostics' `checkProviders` array argument via di.xml and
 * run after core's own checks, in registration order. Return the same Check[] shape core's checks
 * do; an empty array is a valid "nothing to report".
 */
interface CheckProviderInterface
{
    /**
     * @return Check[]
     */
    public function check(): array;
}
