<?php

declare(strict_types=1);

namespace Building\Domain\DomainEvent;

use Prooph\EventSourcing\AggregateChanged;

final class CheckOutAnomalyDetected extends AggregateChanged
{
    public static function fromUser($username, $buildingId)
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
