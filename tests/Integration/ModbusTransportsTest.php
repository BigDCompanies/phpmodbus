<?php

namespace Tests\Integration;

use PHPModbus\DeviceException;
use PHPModbus\ModbusMaster;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LoopbackServer as Server;
use Tests\Support\SocketTestCase;

final class ModbusTransportsTest extends SocketTestCase
{
    public function test_udp_reads_one_datagram_and_ignores_a_stale_transaction(): void
    {
        $server = $this->server(function ($socket) {
            $request = stream_socket_recvfrom($socket, 260, 0, $peer);
            $stale = Server::frame($request, "\x04\x02\x00\x01");
            $stale[1] = chr(ord($stale[1]) ^ 1);
            stream_socket_sendto($socket, $stale, 0, $peer);
            stream_socket_sendto($socket, Server::frame($request, "\x04\x02\x00\x2a"), 0, $peer);
        }, 'udp');
        $this->assertSame([0, 42], $this->client($server, 'UDP')->fc4(1, 0, 1));
        $server->finish();
    }

    #[DataProvider('badDatagrams')]
    public function test_udp_rejects_invalid_datagrams_without_a_retry(string $kind): void
    {
        $server = $this->server(function ($socket) use ($kind) {
            $request = stream_socket_recvfrom($socket, 260, 0, $peer);
            $frame = Server::frame($request, "\x03\x02\x00\x2a");
            if ($kind === 'short') $frame = substr($frame, 0, -1);
            if ($kind === 'trailing') $frame .= "\x00";
            if ($kind === 'oversized') $frame .= str_repeat("\x00", 300);
            if ($kind === 'unit') $frame[6] = chr(9);
            stream_socket_sendto($socket, $frame, 0, $peer);
        }, 'udp');
        $client = $this->client($server, 'UDP');
        $client->read_retries = 1;
        $error = $this->failure(fn () => $client->fc3(1, 0, 1));
        $this->assertNull($error->getPrevious());
        $server->finish();
    }

    public static function badDatagrams(): array
    {
        return [['short'], ['trailing'], ['oversized'], ['unit']];
    }

    public function test_rtu_write_echo_and_exception_reply_keep_the_connection_synchronized(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            $request = Server::read($connection, 10);
            fwrite($connection, $request); // FC22 echoes address and both masks.
            Server::read($connection, 8);
            $exception = "\x01\x83\x02";
            $exception .= (new ModbusMaster('127.0.0.1'))->crc16($exception);
            fwrite($connection, $exception);
            $write = Server::read($connection, 8);
            fwrite($connection, $write);
        });
        $client = $this->client($server, 'RTU_TCP');
        $client->connect();
        $this->assertTrue($client->fc22(1, 0, 0xff00, 0x00ff));
        try {
            $client->fc3(1, 0, 1);
            $this->fail('Expected an RTU device exception');
        } catch (DeviceException $error) {
            $this->assertSame(2, $error->getCode());
        }
        $this->assertTrue($client->fc6(1, 0, [42]));
        $client->disconnect();
        $server->finish();
    }

    public function test_explicit_connect_is_idempotent_and_honors_a_local_bind(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            if (strpos(stream_socket_get_name($connection, true), '127.0.0.1:') !== 0) {
                throw new \RuntimeException('Unexpected client bind');
            }
            $request = Server::request($connection);
            fwrite($connection, Server::frame($request, "\x04\x02\x00\x2a"));
        });
        $client = $this->client($server);
        $client->client = '127.0.0.1';
        $client->client_port = 0;
        $this->assertTrue($client->connect());
        $this->assertTrue($client->connect());
        $this->assertSame([0, 42], $client->fc4(1, 0, 1));
        $client->disconnect();
        $server->finish();
    }
}
