<?php
namespace Volante\SkyBukkit\RelayServer\Src\Messaging;

use Ratchet\ConnectionInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Volante\SkyBukkit\RelayServer\Src\Authentication\AuthenticationMessage;
use Volante\SkyBukkit\RelayServer\Src\Authentication\UnauthorizedException;
use Volante\SkyBukkit\RelayServer\Src\Network\Client;
use Volante\SkyBukkit\RelayServer\Src\Network\ClientFactory;
use Volante\SkyBukkit\RelayServer\Src\Role\IntroductionMessage;

/**
 * Class MessageRelayService
 * @package Volante\SkyBukkit\Monitor\Src\FlightStatus\Network
 */
class MessageRelayService
{
    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var MessageService
     */
    private $messageService;

    /**
     * @var ClientFactory
     */
    private $clientFactory;

    /**
     * @var Client[]
     */
    private $clients = [];

    /**
     * MessageRelayService constructor.
     * @param OutputInterface $output
     * @param MessageService $messageService
     * @param ClientFactory $clientFactory
     */
    public function __construct(OutputInterface $output, MessageService $messageService = null, ClientFactory $clientFactory = null)
    {
        $this->output = $output;
        $this->messageService = $messageService ?: new MessageService();
        $this->clientFactory = $clientFactory ?: new ClientFactory();
    }

    /**
     * @param ConnectionInterface $connection
     */
    public function newClient(ConnectionInterface $connection)
    {
        $this->clients[] = $this->clientFactory->get($connection);
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $message
     */
    public function newMessage(ConnectionInterface $connection, string $message)
    {
        try {
            $client = $this->findClient($connection);
            $message = $this->messageService->handle($client, $message);

            switch (get_class($message)) {
                case AuthenticationMessage::class:
                    /** @var AuthenticationMessage $message */
                    $this->handleAuthenticationMessage($message);
                    break;
                case IntroductionMessage::class:
                    /** @var IntroductionMessage $message */
                    $this->handleIntroductionMessage($message);
                    break;
            }
        } catch (\Exception $e) {
            $this->output->writeln('<error>[MessageRelayService] ' . $e->getMessage() . '</error>');
        }
    }

    /**
     * @param AuthenticationMessage $message
     */
    protected function handleAuthenticationMessage(AuthenticationMessage $message)
    {
        if ($message->getToken() === getenv('AUTH_TOKEN')) {
            $message->getSender()->setAuthenticated();
        } else {
            $this->disconnectClient($message->getSender());
            throw new UnauthorizedException('Client ' . $message->getSender()->getId() . ' tried to authenticate with wrong token!');
        }
    }

    /**
     * @param IntroductionMessage $message
     */
    protected function handleIntroductionMessage(IntroductionMessage $message)
    {
        $this->authenticate($message->getSender());
        $message->getSender()->setRole($message->getRole());
    }

    /**
     * @param Client $client
     */
    private function authenticate(Client $client)
    {
        if (!$client->isAuthenticated()) {
            $this->disconnectClient($client);
            throw new UnauthorizedException('Client ' . $client->getId() . ' tried to perform unauthenticated action!');
        }
    }

    /**
     * @param Client $removedClient
     */
    private function disconnectClient(Client $removedClient)
    {
        $removedClient->getConnection()->close();
        foreach ($this->clients as $i => $client) {
            if ($client === $removedClient) {
                unset($this->clients[$i]);
                $this->clients = array_values($this->clients);
                break;
            }
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @return Client
     */
    private function findClient(ConnectionInterface $connection) : Client
    {
        foreach ($this->clients as $client) {
            if ($client->getConnection() === $connection) {
                return $client;
            }
        }

        throw new \RuntimeException('No connected client found!');
    }
}