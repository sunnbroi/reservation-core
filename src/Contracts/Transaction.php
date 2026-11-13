<?php

declare(strict_types=1);

namespace Reservation\Contracts;

interface Transaction
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
