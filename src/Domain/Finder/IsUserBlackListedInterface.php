<?php

declare(strict_types=1);

namespace Building\Domain\Finder;

interface IsUserBlackListedInterface
{
    /**
     * @param string $username
     *
     * @return bool
     */
    public function __invoke(string $username) : bool;
}
