<?php

declare(strict_types=1);

namespace Building\Domain\DomainEvent;

use Prooph\EventSourcing\AggregateChanged;

final class UserCheckedOut extends AggregateChanged
{
    public static function fromBuilding($username, $buildingId)
    {
        return self::occur(
            (string) $buildingId,
            [
                'username' => $username,
            ]
        );
    }

    public function username() : string
    {
        return $this->payload['username'];
    }
}
