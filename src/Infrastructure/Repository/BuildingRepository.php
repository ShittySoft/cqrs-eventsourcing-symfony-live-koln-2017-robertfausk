<?php

declare(strict_types=1);

namespace Building\Infrastructure\Repository;

use Building\Domain\Aggregate\Building;
use Building\Domain\Repository\BuildingRepositoryInterface;
use Prooph\EventStore\Aggregate\AggregateRepository;
use Rhumsaa\Uuid\Uuid;

final class BuildingRepository implements BuildingRepositoryInterface
{
    /**
     * @var AggregateRepository
     */
    private $aggregateRepository;

    public function __construct(AggregateRepository $aggregateRepository)
    {
        $this->aggregateRepository = $aggregateRepository;
    }

    /**
     * @param Building $building
     *
     * @return void
     */
    public function add(Building $building)
    {
        $this->aggregateRepository->addAggregateRoot($building);
    }

    /**
     * @param Uuid $id
     *
     * @return Building
     */
    public function get(Uuid $id) : Building
    {
        return $this->aggregateRepository->getAggregateRoot($id->toString());
    }
}
