<?php

declare(strict_types=1);

namespace Building\Domain\Command;

use Prooph\Common\Messaging\Command;
use Rhumsaa\Uuid\Uuid;

/**
 * Only data / value objects - no entities! all have to be serializable
 *
 * @package Building\Domain\Command
 */
final class CheckOutUserFromBuilding extends Command // extends Command shouldnt be used but simplier for now; should be framework independent
{
    /**
     * @var string
     */
    private $username;
    /**
     * @var Uuid
     */
    private $buildingId;

    private function __construct(string $username, Uuid $buildingId)
    {
        $this->init();

        $this->username = $username;
        $this->buildingId = $buildingId;
    }

    /**
     * @param string $username
     * @param Uuid   $buildingId
     *
     * @return CheckInUserIntoBuilding
     */
    public static function fromUsernameAndBuildingUuid(string $username, Uuid $buildingId)
    {
        return new self($username, $buildingId);
    }

    public function username() : string
    {
        return $this->username;
    }

    public function buildingId() : Uuid
    {
        return $this->buildingId;
    }

    /**
     * {@inheritDoc}
     */
    public function payload() : array
    {
        return [
            'username' => $this->username,
            'buildingId' => $this->buildingId->toString(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function setPayload(array $payload)
    {
        $this->username = (string) $payload['username'];
        $this->buildingId = Uuid::fromString($payload['buildingId']);
    }
}
