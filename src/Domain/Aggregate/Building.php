<?php

declare(strict_types=1);

namespace Building\Domain\Aggregate;

use Building\Domain\DomainEvent\NewBuildingWasRegistered;
use Building\Domain\DomainEvent\UserCheckedIn;
use Building\Domain\DomainEvent\UserCheckedOut;
use Building\Domain\DomainEvent\UserDoubleCheckedIn;
use Building\Domain\DomainEvent\UserDoubleCheckedOut;
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
     * @var bool[] indexed by username
     */
    private $checkedInUsers;

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
                'name' => $name,
                'usernames' => [],
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
        if (array_key_exists($username, $this->checkedInUsers)) {
            $this->recordThat(
                UserDoubleCheckedIn::fromBuilding($username, $this->uuid)
            );
        }

        $this->recordThat(
            UserCheckedIn::toBuilding($username, $this->uuid)
        );
    }

    public function checkOutUserFromBuilding(string $username)
    {
        if (!array_key_exists($username, $this->checkedInUsers)) {
            $this->recordThat(
                UserDoubleCheckedOut::fromBuilding($username, $this->uuid)
            );
        }

        $this->recordThat(
            UserCheckedOut::fromBuilding($username, $this->uuid)
        );
    }

    /** automatically called when event is fired */
    public function whenNewBuildingWasRegistered(NewBuildingWasRegistered $event)
    {
        $this->uuid = $event->uuid();
        $this->name = $event->name();
        $this->checkedInUsers = [];
    }

    /** automatically called when event is fired */
    public function whenUserCheckedIn(UserCheckedIn $event)
    {
        $this->checkedInUsers[$event->username()] = null;
    }

    /** automatically called when event is fired */
    public function whenUserCheckedOut(UserCheckedOut $event)
    {
        unset($this->checkedInUsers[$event->username()]);
    }

    /** automatically called when event is fired */
    public function whenUserDoubleCheckedIn(UserCheckedIn $event)
    {
        $this->checkedInUsers[$event->username()] = null;
    }

    /** automatically called when event is fired */
    public function whenCheckInAnomalyDetected(UserCheckedOut $event)
    {
        unset($this->checkedInUsers[$event->username()]);
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
