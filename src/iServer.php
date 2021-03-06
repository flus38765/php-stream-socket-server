<?php
namespace sockets;

/**
 * Interface iServer
 * @package sockets/php-stream-socket-server
 */
interface iServer
{
    /**
     * iServer constructor.
     * @param string $callerJobId
     */
    public function __construct(string $callerJobId);

    /**
     * @param StreamSocketServer $streamSocketServer
     * @return Server
     */
    public function attachStreamSocketServer(StreamSocketServer &$streamSocketServer): Server;

    /**
     * @return bool
     */
    public function start(): bool;

    /**
     * @return bool
     */
    public function stop(): bool;

    /**
     * @param $data
     * @return bool
     */
    public function broadcast($data): bool;

    /**
     * @param string $clientId
     * @return mixed
     */
    public function getClient(string $clientId);

    /**
     * @param Client $client
     * @return bool
     */
    public function disconnectClient(Client &$client): bool;
}
