<?php

declare(strict_types=1);

namespace Building\Domain\Aggregate;

use Building\Domain\DomainEvent\NewBuildingWasRegistered;
use Building\Domain\DomainEvent\UserCheckedIn;
use Prooph\EventSourcing\AggregateRoot;
use Rhumsaa\Uuid\Uuid;

final class Building extends AggregateRoot
{
    /**
     * @var Uuid
     */
    private $uuid;

    /**
     * @var string
     */
    private $name;

    /**
     * @param string $name
     *
     * @return Building
     */
    public static function new(string $name) : self
    {
        $self = new self();

        $self->recordThat(NewBuildingWasRegistered::occur(
            (string) Uuid::uuid4(),
            [
                'name' => $name
            ]
        ));

        return $self;
    }

    /**
     * @param string $username
     *
     * @return $this
     */
    public function checkInUserIntoBuilding(string $username)
    {
        $this->recordThat(
            UserCheckedIn::toBuilding($username, $this->uuid)
        );
    }

    public function checkOutUser(string $username)
    {
        // @TODO to be implemented
    }

    /** automatically called when event is fired */
    public function whenNewBuildingWasRegistered(NewBuildingWasRegistered $event)
    {
        $this->uuid = $event->uuid();
        $this->name = $event->name();
    }

    /** automatically called when event is fired */
    public function whenUserCheckedIn(UserCheckedIn $event)
    {
//        can be empty // no need to do anything
    }

    /**
     * {@inheritDoc}
     */
    protected function aggregateId() : string
    {
        return (string) $this->uuid;
    }

    /**
     * {@inheritDoc}
     */
    public function id() : string
    {
        return $this->aggregateId();
    }
}
