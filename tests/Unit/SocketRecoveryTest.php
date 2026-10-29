<?php

namespace Tests\Unit;

use Tests\Support\LoopbackServer as Server;
use Tests\Support\SocketFaults as Faults;
use Tests\Support\SocketTestCase;

require_once __DIR__ . '/../Support/SocketFaults.php';

final class SocketRecoveryTest extends SocketTestCase
{
    protected function tearDown(): void
    {
        Faults::reset();
        parent::tearDown();
    }

    public function test_partial_writes_and_interrupted_syscalls_do_not_duplicate_a_write_command(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            $request = Server::request($connection);
            if (substr($request, 6) !== "\x01\x06\x00\x07\x00\x2a") {
                throw new \RuntimeException('Write command was corrupted or duplicated');
            }
            fwrite($connection, $request);
        });
        Faults::$writes = [['limit' => 2], ['error' => SOCKET_EINTR], ['error' => SOCKET_EAGAIN], ['limit' => 3]];
        Faults::$reads = [SOCKET_EINTR, SOCKET_EWOULDBLOCK];
        Faults::$selects = [SOCKET_EINTR];
        $this->assertTrue($this->client($server)->fc6(1, 7, [42]));
        $this->assertSame([], Faults::$writes);
        $this->assertSame([], Faults::$reads);
        $this->assertSame([], Faults::$selects);
        $server->finish();
    }

    public function test_permanent_select_error_is_not_mistaken_for_a_stale_interrupt(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            if (fread($connection, 1) !== '') {
                throw new \RuntimeException('Unexpected request after select failure');
            }
        });
        $client = $this->client($server);
        $client->connect();
        Faults::$socketError = SOCKET_EINTR;
        Faults::$globalError = SOCKET_EINTR;
        Faults::$selects = [SOCKET_EINVAL];
        $error = $this->failure(fn () => $client->fc3(1, 0, 1));
        $this->assertSame(SOCKET_EINVAL, $error->getCode());
        $server->finish();
    }

    public function test_would_block_writes_are_bounded_by_the_same_deadline(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            if (fread($connection, 1) !== '') {
                throw new \RuntimeException('Unexpected complete request');
            }
        });
        $client = $this->client($server);
        $client->timeout_sec = 0.05;
        Faults::$blockWrites = true;
        $start = hrtime(true);
        $this->assertStringContainsString('Watchdog', $this->failure(fn () => $client->fc6(1, 0, [42]))->getMessage());
        $this->assertLessThan(0.25, (hrtime(true) - $start) / 1e9);
        $server->finish();
    }

    public function test_fatal_write_error_cleans_up_without_replaying(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            if (fread($connection, 1) !== '') {
                throw new \RuntimeException('Unexpected bytes after failed write');
            }
        });
        $client = $this->client($server);
        $client->read_retries = 1;
        Faults::$writes = [['error' => SOCKET_EPIPE]];
        $this->assertSame(SOCKET_EPIPE, $this->failure(fn () => $client->fc6(1, 0, [42]))->getCode());
        $server->finish();
    }
}
