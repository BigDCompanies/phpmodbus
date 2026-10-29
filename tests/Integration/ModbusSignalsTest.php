<?php

namespace Tests\Integration;

use Tests\Support\LoopbackServer as Server;
use Tests\Support\SocketTestCase;

final class ModbusSignalsTest extends SocketTestCase
{
    public function test_graceful_signal_runs_promptly_without_losing_a_partial_reply_or_resending(): void
    {
        $server = $this->server(function ($listener, $parent) {
            $connection = Server::accept($listener);
            $request = Server::request($connection);
            $reply = Server::frame($request, "\x02\x01\x01");
            fwrite($connection, substr($reply, 0, -1));
            usleep(50000);
            posix_kill($parent, SIGTERM);
            usleep(150000);
            fwrite($connection, substr($reply, -1));
            $next = Server::request($connection);
            if (substr($next, 8, 2) !== "\x00\x01") {
                throw new \RuntimeException('Interrupted read was resent instead of continued');
            }
            fwrite($connection, Server::frame($next, "\x02\x01\x00"));
        });
        $signalAt = null;
        $start = hrtime(true);
        $handler = function () use (&$signalAt, $start) { $signalAt = (hrtime(true) - $start) / 1e9; };
        pcntl_signal(SIGTERM, $handler);
        $client = $this->client($server);
        $client->read_retries = 1;
        $client->connect();
        $this->assertSame([true], $client->fc2(1, 0, 1));
        $this->assertSame([false], $client->fc2(1, 1, 1));
        $this->assertNotNull($signalAt);
        $this->assertLessThan(0.17, $signalAt, 'Signal must run before the delayed reply finishes');
        $this->assertSame($handler, pcntl_signal_get_handler(SIGTERM));
        $this->assertTrue(pcntl_async_signals());
        $client->disconnect();
        $server->finish();
    }

    public function test_repeated_signals_do_not_extend_the_read_deadline(): void
    {
        $server = $this->server(function ($listener, $parent) {
            $connection = Server::accept($listener);
            Server::request($connection);
            foreach ([SIGUSR2, SIGCONT, SIGTERM] as $signal) {
                usleep(50000);
                posix_kill($parent, $signal);
            }
            // EOF demonstrates that the timeout closed the socket as well.
            if (fread($connection, 1) !== '') {
                throw new \RuntimeException('Unexpected second request');
            }
        });
        $signals = [];
        foreach ([SIGUSR2, SIGCONT, SIGTERM] as $signal) {
            pcntl_signal($signal, function ($received) use (&$signals) { $signals[] = $received; });
        }
        $client = $this->client($server);
        $client->timeout_sec = 0.25;
        $start = hrtime(true);
        $error = $this->failure(fn () => $client->fc3(1, 0, 1));
        $elapsed = (hrtime(true) - $start) / 1e9;
        $this->assertStringContainsString('Watchdog', $error->getMessage());
        $this->assertSame([SIGUSR2, SIGCONT, SIGTERM], $signals);
        $this->assertGreaterThan(0.22, $elapsed);
        $this->assertLessThan(0.38, $elapsed);
        $server->finish();
    }

    public function test_exception_from_an_application_signal_handler_is_not_retried(): void
    {
        $server = $this->server(function ($listener, $parent) {
            $connection = Server::accept($listener);
            Server::request($connection);
            posix_kill($parent, SIGTERM);
            if (fread($connection, 1) !== '') {
                throw new \RuntimeException('Expected cleanup after cancellation');
            }
        });
        $cancellation = new \RuntimeException('Application cancelled the operation');
        pcntl_signal(SIGTERM, function () use ($cancellation) { throw $cancellation; });
        $client = $this->client($server);
        $client->read_retries = 1;
        try {
            $client->fc3(1, 0, 1);
            $this->fail('Expected application cancellation');
        } catch (\RuntimeException $error) {
            $this->assertSame($cancellation, $error);
        }
        $client->disconnect();
        $server->finish();
    }

    public function test_signal_does_not_shorten_the_required_inter_request_delay(): void
    {
        $server = $this->server(function ($listener, $parent) {
            $connection = Server::accept($listener);
            $request = Server::request($connection);
            $start = hrtime(true);
            fwrite($connection, Server::frame($request, "\x03\x02\x00\x01"));
            usleep(50000);
            posix_kill($parent, SIGTERM);
            $next = Server::request($connection);
            if ((hrtime(true) - $start) / 1e9 < 0.22) {
                throw new \RuntimeException('Signal shortened the configured request delay');
            }
            fwrite($connection, Server::frame($next, "\x03\x02\x00\x2a"));
        });
        $handled = false;
        pcntl_signal(SIGTERM, function () use (&$handled) { $handled = true; });
        $client = $this->client($server);
        $client->connect();
        $client->request_delay = 0.25;
        $this->assertSame([0, 1], $client->fc3(1, 0, 1));
        $this->assertSame([0, 42], $client->fc3(1, 1, 1));
        $this->assertTrue($handled);
        $client->disconnect();
        $server->finish();
    }

    public function test_job_timeout_can_terminate_a_process_during_a_socket_wait(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            Server::request($connection);
            if (fread($connection, 1) !== '') {
                throw new \RuntimeException('Worker sent another request after its job timeout');
            }
        });
        $start = hrtime(true);
        $process = proc_open([PHP_BINARY, __DIR__ . '/../Fixtures/timeout-worker.php', (string) $server->port],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertSame(73, $exit, $stdout . $stderr);
        $this->assertLessThan(2.0, (hrtime(true) - $start) / 1e9);
        $server->finish();
    }
}
