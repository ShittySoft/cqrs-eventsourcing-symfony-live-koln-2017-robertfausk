<?php

declare(strict_types=1);

namespace Building\Domain\Repository;

use Building\Domain\Aggregate\Building;
use Rhumsaa\Uuid\Uuid;

interface BuildingRepositoryInterface
{
    /**
     * @param Building $building
     *
     * @return void
     */
    public function add(Building $building);

    /**
     * @param Uuid $id
     *
     * @return Building
     */
    public function get(Uuid $id) : Building;
}
