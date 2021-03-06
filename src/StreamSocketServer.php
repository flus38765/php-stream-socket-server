<?php
declare(strict_types = 1);
namespace sockets;

/**
 * Class StreamSocketServer
 * @package sockets/php-stream-socket-server
 */
class StreamSocketServer
{
    const U_SLEEP = 20;
    /**
     * @var array
     */
    protected $clients = [];
    /**
     * @var resource
     */
    protected $server = false;
    /**
     * @var array
     */
    private $_config = [];
    /**
     * @var \Closure
     */
    private $_callback;
    /**
     * @var bool
     */
    public $debug;

    /**
     * StreamSocketServer constructor.
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->_config = array_merge(static::_default_config(), $this->_config, $config);
        $this->setDebug(boolval($this->_config['DEBUG'] ?? false));
    }

    /**
     * @return array
     */
    final static protected function _default_config(): array
    {
        $port = getenv('C9_PORT');
        if (!is_numeric($port)) {
            $port = getenv('PORT');
        }
        if (!is_numeric($port)) {
            $port = 8082;
        }
        $ip = getenv('C9_IP');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = getenv('IP');
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = '127.0.0.1';
        }
        $hostname = getenv('C9_HOSTNAME');
        if (filter_var($hostname, FILTER_VALIDATE_URL) === false) {
            $hostname = getenv('HOSTNAME');
        }
        if (filter_var($hostname, FILTER_VALIDATE_URL) === false) {
            $hostname = 'localhost';
        }

        return [
            'SOCKET_PROTO' => 'tcp',
            'IP'           => $ip,
            'HOSTNAME'     => $hostname,
            'SOCKET_PORT'  => $port,
        ];
    }

    /**
     * @return StreamSocketServer
     */
    final public function start(): StreamSocketServer
    {
        if ($this->getDebug()) {
            echo "[INFO][".__CLASS__."::".__FUNCTION__."] ".strtoupper($this->_config['SOCKET_PROTO'])." listening on "
                 .$this->_config['HOSTNAME'].":".$this->_config['SOCKET_PORT']."\n";
        }
        $this->server = stream_socket_server(
            strtolower($this->_config['SOCKET_PROTO'])."://".$this->_config['IP'].":".$this->_config['SOCKET_PORT'],
            $errno,
            $errorMessage
        );
        if ($this->server === false) {
            error_log("[ERROR][".__CLASS__."::".__FUNCTION__."] Could not bind to socket: $errorMessage");
            if ($this->getDebug()) {
                echo "[ERROR][".__CLASS__."::".__FUNCTION__."] Could not bind to socket: $errorMessage";
            }
        }

        return $this;
    }

    /**
     * @return bool
     */
    final public function isRunning(): bool
    {
        return !$this->isStopped();
    }

    /**
     * @return bool
     */
    final public function isStopped(): bool
    {
        return $this->server === false;
    }

    /**
     * @param \Closure $callback
     * @return StreamSocketServer
     */
    final public function registerCallback(\Closure $callback): StreamSocketServer
    {
        if (is_callable($callback)) {
            $this->_callback = $callback;
        }

        return $this;
    }

    final public function listen()
    {
        if ($this->isRunning()) {
            if ($handle = @stream_socket_accept($this->server, -1)) {
                $client = new ClientStreamSocket(new Client());
                $client->attachClientHandle($handle);
                $client->setDebug($this->getDebug());
                if ($this->getDebug()) {
                    echo "[INFO][".__CLASS__."::".__FUNCTION__."] received client socket {$client->jobId}\n";
                }
                if ($client->validateWebSocket()) {
                    $client->upgradeWebSocket();
                }
                $this->clients[] = &$client;
                $this->launchJob($client);
                $this->system('client_connected', $client->getJobId());
            }
            $this->listen();
        }
    }

    /**
     * @param ClientStreamSocket $client
     */
    final protected function launchJob(ClientStreamSocket &$client)
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            // Problem launching the job
            error_log("[ERROR][".__CLASS__."::".__FUNCTION__."] Could not launch new job, exiting\n");
            if ($this->getDebug()) {
                echo "[ERROR][".__CLASS__."::".__FUNCTION__."] Could not launch new job, exiting\n";
            }
            $client->disconnect();
        } elseif ($pid) {
            //echo "[WARN] encountered parent process in StreamSocketServer::launchJob\n";
        } else {
            //Forked child
            if ($this->getDebug()) {
                echo "[INFO][".__CLASS__."::".__FUNCTION__."] Doing something fun in pid ".getmypid()."\n";
            }
            $this->await($client);
        }
    }

    /**
     * @param string|object|array $data
     * @param string|bool         $clientJobId
     * @return bool
     * @internal ClientStreamSocket $client
     */
    final public function broadcast($data, $clientJobId = false): bool
    {
        if ($this->getDebug()) {
            echo "[INFO][".__CLASS__."::".__FUNCTION__."] ".time()."\n";
        }
        if (!$this->isRunning()) {
            return false;
        }
        foreach ($this->clients as $client) {
            if ($client->jobId === $clientJobId) {
                continue;
            }
            if ($this->getDebug()) {
                echo "[INFO][".__CLASS__."::".__FUNCTION__."] broadcast to client socket {$client->jobId}\n";
            }
            if (!$client($data)) {
                $this->removeClient($client);
            }
        }

        return true;
    }

    /**
     * @param string $type
     * @param string $message
     * @return bool
     * @internal ClientStreamSocket $client
     */
    final public function system(string $type, string $message): bool
    {
        if ($this->getDebug()) {
            echo "[INFO][".__CLASS__."::".__FUNCTION__."] ".time()."\n";
        }
        if (!$this->isRunning()) {
            return false;
        }
        foreach ($this->clients as $client) {
            if ($this->getDebug()) {
                echo "[INFO][".__CLASS__."::".__FUNCTION__."] broadcast to client socket {$client->jobId}\n";
            }
            if (!$client->systemSend($type, $message)) {
                $this->removeClient($client);
            }
        }

        return true;
    }

    /**
     * @param ClientStreamSocket $brokenPipeClient
     * @internal ClientStreamSocket $client
     * @return bool
     */
    final public function removeClient(ClientStreamSocket &$brokenPipeClient): bool
    {
        foreach ($this->clients as $k => $client) {
            if ($client->jobId === $brokenPipeClient->jobId) {
                unset($this->clients[$k]);

                return true;
            }
        }

        return false;
    }

    /**
     * @param string $jobId
     * @internal ClientStreamSocket $client
     * @return bool
     */
    final public function removeClientByJobId(string $jobId): bool
    {
        foreach ($this->clients as $k => $client) {
            if ($client->jobId === $jobId) {
                unset($this->clients[$k]);

                return true;
            }
        }

        return false;
    }

    /**
     * @param array              $data
     * @param ClientStreamSocket $client
     * @return bool
     */
    final protected function processMessage(array $data, ClientStreamSocket &$client): bool
    {
        if ($this->getDebug()) {
            echo "[INFO][".__CLASS__."::".__FUNCTION__."] ".time()."\n";
        }
        if (!$this->isRunning()) {
            return false;
        }
        $server = new Server($client->getJobId(), $data);
        $server->attachStreamSocketServer($this);
        $responseData = call_user_func($this->_callback, $data, $client->getClient(), $server) ?? false;
        if (!empty($responseData) && !$client($responseData)) {
            $this->removeClient($client);
        }

        return true;
    }

    /**
     * @param ClientStreamSocket $client
     */
    final protected function await(ClientStreamSocket &$client)
    {
        usleep(self::U_SLEEP);
        if ($this->getDebug()) {
            echo "[INFO][".__CLASS__."::".__FUNCTION__."] ".time()."\n";
        }
        if ($client->pendingMessage()) {
            if ($client->isWebSocket) {
                $data = $client->getData();
                $this->processMessage($data, $client);
                $this->await($client);
            } else {
                $data = $client->getDataRaw();

                $this->system('sys_admin', $data);
                $client->disconnect();
            }
        }
    }

    /**
     * @return bool
     */
    final public function getDebug(): bool
    {
        return $this->debug ?? false;
    }

    /**
     * @param bool $debug
     * @return StreamSocketServer
     */
    final public function setDebug(bool $debug): StreamSocketServer
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * @return StreamSocketServer
     * @internal ClientStreamSocket $client
     */
    final public function stop(): StreamSocketServer
    {
        if ($this->isRunning()) {
            foreach ($this->clients as $client) {
                $client->disconnect();
            }
            $this->clients = [];
            fclose($this->server);
            $this->server = false;
        }

        return $this;
    }

    /**
     * @param string $jobId
     * @return Client|bool
     * @internal ClientStreamSocket $client
     */
    final public function getClientByJobId(string $jobId)
    {
        foreach ($this->clients as $k => $client) {
            if ($client->jobId === $jobId) {
                return $this->clients[$k]->getClient();
            }
        }

        return false;
    }

    final public function __destruct()
    {
        if ($this->getDebug()) {
            echo "[INFO][".__CLASS__."::".__FUNCTION__."] Thread closing\n";
        }
    }
}
