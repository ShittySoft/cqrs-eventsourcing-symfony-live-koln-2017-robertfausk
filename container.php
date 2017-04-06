<?php

declare(strict_types=1);

namespace Building\App;

use Bernard\Driver\FlatFileDriver;
use Bernard\Producer;
use Bernard\Queue;
use Bernard\QueueFactory;
use Bernard\QueueFactory\PersistentFactory;
use Building\Domain\Aggregate\Building;
use Building\Domain\Command;
use Building\Domain\DomainEvent\CheckInAnomalyDetected;
use Building\Domain\DomainEvent\UserCheckedIn;
use Building\Domain\DomainEvent\UserCheckedOut;
use Building\Domain\Repository\BuildingRepositoryInterface;
use Building\Domain\Finder\IsUserBlackListedInterface;
use Building\Infrastructure\Repository\BuildingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOSqlite\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\SchemaException;
use Interop\Container\ContainerInterface;
use Prooph\Common\Event\ActionEvent;
use Prooph\Common\Event\ActionEventEmitter;
use Prooph\Common\Event\ActionEventListenerAggregate;
use Prooph\Common\Event\ProophActionEventEmitter;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\NoOpMessageConverter;
use Prooph\EventSourcing\AggregateChanged;
use Prooph\EventSourcing\EventStoreIntegration\AggregateTranslator;
use Prooph\EventStore\Adapter\Doctrine\DoctrineEventStoreAdapter;
use Prooph\EventStore\Adapter\Doctrine\Schema\EventStoreSchema;
use Prooph\EventStore\Adapter\PayloadSerializer\JsonPayloadSerializer;
use Prooph\EventStore\Aggregate\AggregateRepository;
use Prooph\EventStore\Aggregate\AggregateType;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Stream\StreamName;
use Prooph\EventStoreBusBridge\EventPublisher;
use Prooph\EventStoreBusBridge\TransactionManager;
use Prooph\ServiceBus\Async\MessageProducer;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\Message\Bernard\BernardMessageProducer;
use Prooph\ServiceBus\Message\Bernard\BernardSerializer;
use Prooph\ServiceBus\MessageBus;
use Prooph\ServiceBus\Plugin\ServiceLocatorPlugin;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Zend\ServiceManager\ServiceManager;

require_once __DIR__ . '/vendor/autoload.php';

return new ServiceManager([
    'factories' => [
        Connection::class => function () {
            $connection = DriverManager::getConnection([
                'driverClass' => Driver::class,
                'path'        => __DIR__ . '/data/db.sqlite3',
            ]);

            try {
                $schema = $connection->getSchemaManager()->createSchema();

                EventStoreSchema::createSingleStream($schema, 'event_stream', true);

                foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
                    $connection->exec($sql);
                }
            } catch (SchemaException $ignored) {
            }

            return $connection;
        },

        EventStore::class                  => function (ContainerInterface $container) {
            $eventBus   = new EventBus();
            $eventStore = new EventStore(
                new DoctrineEventStoreAdapter(  // add details to event; extend details here; e.g. mappings for table name
                    $container->get(Connection::class),
                    new FQCNMessageFactory(),
                    new NoOpMessageConverter(),
                    new JsonPayloadSerializer()
                ),
                new ProophActionEventEmitter()
            );

            $eventBus->utilize(new class ($container, $container) implements ActionEventListenerAggregate
            {
                /**
                 * @var ContainerInterface
                 */
                private $eventHandlers;

                /**
                 * @var ContainerInterface
                 */
                private $projectors;

                public function __construct(
                    ContainerInterface $eventHandlers,
                    ContainerInterface $projectors
                ) {
                    $this->eventHandlers = $eventHandlers;
                    $this->projectors    = $projectors;
                }

                public function attach(ActionEventEmitter $dispatcher)
                {
                    $dispatcher->attachListener(MessageBus::EVENT_ROUTE, [$this, 'onRoute']);
                }

                public function detach(ActionEventEmitter $dispatcher)
                {
                    throw new \BadMethodCallException('Not implemented');
                }

                public function onRoute(ActionEvent $actionEvent)
                {
                    $messageName = (string) $actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME);

                    $handlers = [];

                    $listeners  = $messageName . '-listeners';
                    $projectors = $messageName . '-projectors';

                    if ($this->projectors->has($projectors)) {
                        $handlers = array_merge($handlers, $this->eventHandlers->get($projectors));
                    }

                    if ($this->eventHandlers->has($listeners)) {
                        $handlers = array_merge($handlers, $this->eventHandlers->get($listeners));
                    }

                    if ($handlers) {
                        $actionEvent->setParam(EventBus::EVENT_PARAM_EVENT_LISTENERS, $handlers);
                    }
                }
            });

            (new EventPublisher($eventBus))->setUp($eventStore);

            return $eventStore;
        },

        CommandBus::class                  => function (ContainerInterface $container) : CommandBus {
            $commandBus = new CommandBus();

            $commandBus->utilize(new ServiceLocatorPlugin($container));
            $commandBus->utilize(new class implements ActionEventListenerAggregate {
                public function attach(ActionEventEmitter $dispatcher)
                {
                    $dispatcher->attachListener(MessageBus::EVENT_ROUTE, [$this, 'onRoute']);
                }

                public function detach(ActionEventEmitter $dispatcher)
                {
                    throw new \BadMethodCallException('Not implemented');
                }

                public function onRoute(ActionEvent $actionEvent)
                {
                    $actionEvent->setParam(
                        MessageBus::EVENT_PARAM_MESSAGE_HANDLER,
                        (string) $actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME)
                    );
                }
            });

            $transactionManager = new TransactionManager();
            $transactionManager->setUp($container->get(EventStore::class));

            $commandBus->utilize($transactionManager);

            return $commandBus;
        },

        // ignore this - this is async stuff
        // we'll get to it later

        QueueFactory::class => function () : QueueFactory {
            return new PersistentFactory(
                new FlatFileDriver(__DIR__ . '/data/bernard'),
                new BernardSerializer(new FQCNMessageFactory(), new NoOpMessageConverter())
            );
        },

        Queue::class => function (ContainerInterface $container) : Queue {
            return $container->get(QueueFactory::class)->create('commands');
        },

        MessageProducer::class => function (ContainerInterface $container) : MessageProducer {
            return new BernardMessageProducer(
                new Producer($container->get(QueueFactory::class),new EventDispatcher()),
                'commands'
            );
        },

        // Command -> CommandHandlerFactory
        // this is where most of the work will be done (by you!)
        Command\RegisterNewBuilding::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);

            return function (Command\RegisterNewBuilding $command) use ($buildings) {
                $buildings->add(Building::new($command->name()));
            };
        },
        Command\CheckInUserIntoBuilding::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);
            $blacklist = $container->get(IsUserBlackListedInterface::class);

            return function (Command\CheckInUserIntoBuilding $checkIn) use ($buildings, $blacklist) {
                $building = $buildings->get($checkIn->buildingId());
                $building->checkInUserIntoBuilding($checkIn->username(), $blacklist);
                $buildings->add($building);
            };
        },
        Command\CheckOutUserFromBuilding::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);

            return function (Command\CheckOutUserFromBuilding $checkOut) use ($buildings) {
                $building = $buildings->get($checkOut->buildingId());
                $building->checkOutUserFromBuilding($checkOut->username());
                $buildings->add($building);
            };
        },
        Command\NotifySecurityOfAnomaly::class => function () : callable {
            return function (Command\NotifySecurityOfAnomaly $notifySecurityOfAnomaly) {
                // queries, logic, etc
                // command can create additional events and commands
                error_log(
                    sprintf(
                        'yo somebody is being fishy: %s %s',
                        $notifySecurityOfAnomaly->username(),
                        $notifySecurityOfAnomaly->buildingId()->toString()
                    )
                );
            };
        },
        /**
         * 1. "check in or check out anomaly" event
         * 2. listener for that event
         * 3. listener should fire command "call security"
         * 4. command handler simply prints via 'error_log("something's wrong")'
         */
        CheckInAnomalyDetected::class . '-listeners' => function (ContainerInterface $container) : array {

            // security call
            // email, phone call etc
            // react on double check in
            var_dump('heyho');
            die;

            $commandBus = $container->get(CommandBus::class);

            return [
                function (CheckInAnomalyDetected $anomaly) {

                }
            ];
        },
        // many code in projectors
        // could do same with mysql/doctrine/s3 etc
        // use service of cource
        UserCheckedIn::class . '-projectors' => function (ContainerInterface $container) : array {
            //get dependencies from container
            $eventStore = $container->get(EventStore::class);

            return [
                // naive solution
                function (UserCheckedIn $event) {
                    $file = __DIR__ . '/public/naive-' . $event->aggregateId() . '.json';
                    $users = [];
                    if (is_file($file)) {
                        $users = json_decode(file_get_contents($file), true);
                    }
                    file_put_contents($file, json_encode(array_values(array_unique(array_merge($users, [$event->username()])))));
                },
                // proper solution (goes through full history)
                function (AggregateChanged $event) use ($eventStore) {
                    $users = [];
                    $events = $eventStore->loadEventsByMetadataFrom(
                        new StreamName('event_stream'),
                        ['aggregate_id' => $event->aggregateId()]
                    );

                    foreach ($events as $replayedEvent) {
                        if ($replayedEvent instanceof UserCheckedIn) {
                            $users[$replayedEvent->username()] = null;
                        }

                        if ($replayedEvent instanceof UserCheckedOut) {
                            unset($users[$replayedEvent->username()]);
                        }
                        $file = __DIR__ . '/public/proper-' . $event->aggregateId() . '.json';
                        file_put_contents($file, json_encode(array_values(array_keys($users))));
                    }
                }
            ];
        },
        // many code in projectors
        // could do same with mysql/doctrine/s3 etc
        // use service of cource
        // same as UserCheckedIn
        UserCheckedOut::class . '-projectors' => function (ContainerInterface $container) : array {
            //get dependencies from container
            $eventStore = $container->get(EventStore::class);

            return [
                // naive solution
                function (UserCheckedIn $event) {
                    $file = __DIR__ . '/public/naive-' . $event->aggregateId() . '.json';
                    $users = [];
                    if (is_file($file)) {
                        $users = json_decode(file_get_contents($file), true);
                    }
                    file_put_contents($file, json_encode(array_values(array_unique(array_merge($users, [$event->username()])))));
                },
                // proper solution (goes through full history)
                function (AggregateChanged $event) use ($eventStore) {
                    $users = [];
                    $events = $eventStore->loadEventsByMetadataFrom(
                        new StreamName('event_stream'),
                        ['aggregate_id' => $event->aggregateId()]
                    );

                    foreach ($events as $replayedEvent) {
                        if ($replayedEvent instanceof UserCheckedIn) {
                            $users[$replayedEvent->username()] = null;
                        }

                        if ($replayedEvent instanceof UserCheckedOut) {
                            unset($users[$replayedEvent->username()]);
                        }
                        $file = __DIR__ . '/public/proper-' . $event->aggregateId() . '.json';
                        file_put_contents($file, json_encode(array_values(array_keys($users))));
                    }
                }
            ];
        },
        BuildingRepositoryInterface::class => function (ContainerInterface $container) : BuildingRepositoryInterface {
            return new BuildingRepository(
                new AggregateRepository(
                    $container->get(EventStore::class),
                    AggregateType::fromAggregateRootClass(Building::class),
                    new AggregateTranslator()
                )
            );
        },
        IsUserBlackListedInterface::class => function () : IsUserBlackListedInterface {
            return new class implements IsUserBlackListedInterface
            {
                public function __invoke(string $username) : bool
                {
                    return in_array($username, ['realDonaldTrump']);
                }
            };
        },
    ],
]);
