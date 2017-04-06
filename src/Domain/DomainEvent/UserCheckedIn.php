<?php

declare(strict_types=1);

namespace Building\Domain\DomainEvent;

use Prooph\EventSourcing\AggregateChanged;

final class UserCheckedIn extends AggregateChanged
{
    public static function toBuilding($username, $buildingId)
    {
        return self::occur(
            (string) $buildingId,
            [
                'username' => $username,
            ]
        );
    }
}
