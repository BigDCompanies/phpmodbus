<?php

namespace Tests\Support;

use PHPModbus\CommunicationException;
use PHPModbus\ModbusMaster;
use PHPUnit\Framework\TestCase;

abstract class SocketTestCase extends TestCase
{
    private array $servers = [];
    private array $handlers = [];
    private bool $asyncSignals;

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            $this->markTestSkipped('Real socket tests require pcntl and posix');
        }
        $this->asyncSignals = pcntl_async_signals(true);
        foreach ([SIGTERM, SIGUSR2, SIGCONT, SIGALRM] as $signal) {
            $this->handlers[$signal] = pcntl_signal_get_handler($signal);
        }
        // Match Laravel's handling of unsuppressed socket warnings.
        set_error_handler(function ($severity, $message, $file, $line) {
            if (error_reporting() & $severity) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            return false;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        if ($this->handlers !== []) {
            restore_error_handler();
            foreach ($this->handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($this->asyncSignals);
        }
    }

    protected function server(callable $serve, string $transport = 'tcp'): LoopbackServer
    {
        return $this->servers[] = new LoopbackServer($serve, $transport);
    }

    protected function client(LoopbackServer $server, string $protocol = 'TCP'): ModbusMaster
    {
        $client = new ModbusMaster('127.0.0.1', $protocol, $server->port);
        $client->timeout_sec = 0.5;
        return $client;
    }

    protected function failure(callable $operation): CommunicationException
    {
        try {
            $operation();
        } catch (CommunicationException $error) {
            return $error;
        }
        $this->fail('Expected a communication failure');
    }
}
