<?php

namespace Tests\Integration;

use PHPModbus\CommunicationException;
use PHPModbus\DeviceException;
use PHPModbus\ModbusMaster;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LoopbackServer as Server;
use Tests\Support\SocketTestCase;

final class ModbusExchangeTest extends SocketTestCase
{
    public function test_fragmented_coil_reply_cannot_shift_the_next_register_reply(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            $first = Server::request($connection);
            $reply = Server::frame($first, "\x01\x01\x01");
            foreach (str_split($reply) as $byte) {
                fwrite($connection, $byte);
                usleep(1000);
            }
            $second = Server::request($connection);
            if (substr($first, 0, 2) === substr($second, 0, 2)) {
                throw new \RuntimeException('Consecutive requests reused a transaction ID');
            }
            fwrite($connection, Server::frame($second, "\x03\x04\x41\x20\x00\x00"));
        });
        $client = $this->client($server);
        $client->connect();
        $this->assertSame([true], $client->fc1(1, 0, 1));
        $this->assertSame([0x41, 0x20, 0, 0], $client->fc3(1, 1, 2));
        $client->disconnect();
        $server->finish();
    }

    #[DataProvider('invalidResponses')]
    public function test_rejects_mismatched_or_malformed_responses(string $kind): void
    {
        $server = $this->server(function ($listener) use ($kind) {
            $connection = Server::accept($listener);
            $request = Server::request($connection);
            $frame = Server::frame($request, "\x03\x04\x41\x20\x00\x00");
            switch ($kind) {
                case 'unit': $frame[6] = chr(9); break;
                case 'function': $frame[7] = chr(4); break;
                case 'protocol': $frame[3] = chr(1); break;
                case 'quantity': $frame = Server::frame($request, "\x03\x02\x00\x2a"); break;
                case 'byte count': $frame[8] = chr(3); break;
                case 'short header': $frame = substr($frame, 0, 5); break;
                case 'short body': $frame = substr($frame, 0, -1); break;
                case 'small length': $frame = substr_replace($frame, pack('n', 1), 4, 2); break;
                case 'large length': $frame = substr_replace($frame, pack('n', 255), 4, 2); break;
                case 'exception length': $frame = Server::frame($request, "\x83\x02\x00"); break;
                case 'exception function': $frame = Server::frame($request, "\x84\x02"); break;
                case 'prefix garbage': $frame = "\x01" . $frame; break;
            }
            fwrite($connection, $frame);
            fclose($connection);
        });
        $client = $this->client($server);
        $error = $this->failure(fn () => $client->fc3(1, 0, 2));
        $this->assertNotEmpty($error->getMessage());
        $client->disconnect();
        $client->disconnect();
        $server->finish();
    }

    public static function invalidResponses(): array
    {
        return array_map(fn ($kind) => [$kind], ['unit', 'function', 'protocol', 'quantity', 'byte count',
            'short header', 'short body', 'small length', 'large length', 'exception length',
            'exception function', 'prefix garbage']);
    }

    public function test_skips_complete_stale_frames_without_decoding_their_data(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            $request = Server::request($connection);
            $stale = Server::frame($request, "\x03\x02\x00\x01");
            $stale[1] = chr(ord($stale[1]) ^ 1);
            fwrite($connection, $stale . Server::frame($request, "\x03\x02\x00\x2a"));
        });
        $this->assertSame([0, 42], $this->client($server)->fc3(1, 0, 1));
        $server->finish();
    }

    public function test_device_exception_does_not_retry_or_break_the_next_read(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            $request = Server::request($connection);
            fwrite($connection, Server::frame($request, "\x83\x02"));
            $request = Server::request($connection);
            fwrite($connection, Server::frame($request, "\x03\x02\x00\x2a"));
        });
        $client = $this->client($server);
        $client->read_retries = 1;
        $client->connect();
        try {
            $client->fc3(1, 0, 1);
            $this->fail('Expected a device exception');
        } catch (DeviceException $error) {
            $this->assertSame(2, $error->getCode());
            $this->assertStringContainsString('ILLEGAL DATA ADDRESS', $error->getMessage());
        }
        $this->assertSame([0, 42], $client->fc3(1, 1, 1));
        $client->disconnect();
        $server->finish();
    }

    public function test_failed_read_closes_the_connection_and_later_reads_recover(): void
    {
        $server = $this->server(function ($listener) {
            $bad = Server::accept($listener);
            $request = Server::request($bad);
            fwrite($bad, Server::frame($request, "\x02\x01\x01", 9));
            if (fread($bad, 1) !== '') {
                throw new \RuntimeException('Client reused a contaminated connection');
            }
            fclose($bad);
            $good = Server::accept($listener);
            foreach ([0, 1] as $bit) {
                $request = Server::request($good);
                fwrite($good, Server::frame($request, "\x02\x01" . chr($bit)));
            }
        });
        $client = $this->client($server);
        $client->connect();
        $this->failure(fn () => $client->fc2(1, 0, 1));
        $this->assertSame([false], $client->fc2(1, 1, 1));
        $this->assertSame([true], $client->fc2(1, 2, 1));
        $client->disconnect();
        $server->finish();
    }

    public function test_opt_in_retry_uses_one_new_connection_and_transaction(): void
    {
        $server = $this->server(function ($listener) {
            $first = Server::accept($listener);
            $request = Server::request($first);
            fwrite($first, Server::frame($request, "\x03\x02\x00\x01", 9));
            fclose($first);
            $second = Server::accept($listener);
            $retry = Server::request($second);
            if (substr($request, 0, 2) === substr($retry, 0, 2) || substr($request, 2) !== substr($retry, 2)) {
                throw new \RuntimeException('Retry must change only the transaction ID');
            }
            fwrite($second, Server::frame($retry, "\x03\x02\x00\x2a"));
        });
        $client = $this->client($server);
        $client->read_retries = 1;
        $this->assertSame([0, 42], $client->fc3(1, 0, 1));
        $server->finish();
    }

    public function test_retry_is_bounded_and_preserves_the_first_failure(): void
    {
        $server = $this->server(function ($listener) {
            foreach ([9, 8] as $unit) {
                $connection = Server::accept($listener);
                $request = Server::request($connection);
                fwrite($connection, Server::frame($request, "\x03\x02\x00\x01", $unit));
                fclose($connection);
            }
        });
        $client = $this->client($server);
        $client->read_retries = 1;
        $error = $this->failure(fn () => $client->fc3(1, 0, 1));
        $this->assertInstanceOf(CommunicationException::class, $error->getPrevious());
        $server->finish();
    }

    public function test_timeout_during_a_partial_frame_retries_on_a_fresh_socket(): void
    {
        $server = $this->server(function ($listener) {
            $first = Server::accept($listener);
            $request = Server::request($first);
            fwrite($first, substr(Server::frame($request, "\x03\x02\x00\x01"), 0, 8));
            $second = Server::accept($listener);
            $retry = Server::request($second);
            fwrite($second, Server::frame($retry, "\x03\x02\x00\x2a"));
            fclose($first);
        });
        $client = $this->client($server);
        $client->timeout_sec = 0.1;
        $client->read_retries = 1;
        $this->assertSame([0, 42], $client->fc3(1, 0, 1));
        $server->finish();
    }

    public function test_fragment_arrivals_do_not_restart_the_deadline(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            $request = Server::request($connection);
            $reply = Server::frame($request, "\x03\x02\x00\x2a");
            usleep(100000);
            fwrite($connection, substr($reply, 0, 7));
            usleep(150000);
            @fwrite($connection, substr($reply, 7));
        });
        $client = $this->client($server);
        $client->timeout_sec = 0.2;
        $start = hrtime(true);
        $error = $this->failure(fn () => $client->fc3(1, 0, 1));
        $elapsed = (hrtime(true) - $start) / 1e9;
        $this->assertStringContainsString('Watchdog', $error->getMessage());
        $this->assertGreaterThan(0.17, $elapsed);
        $this->assertLessThan(0.4, $elapsed);
        $server->finish();
    }

    public function test_refused_connections_are_exceptions_and_cleanup_is_idempotent(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $client = new ModbusMaster('127.0.0.1', 'TCP', $port);
        $client->timeout_sec = 0.2;
        $client->read_retries = 1;
        $error = $this->failure(fn () => $client->fc3(1, 0, 1));
        $this->assertStringContainsString('connect', $error->getMessage());
        $this->assertInstanceOf(CommunicationException::class, $error->getPrevious());
        $client->disconnect();
        $client->disconnect();
    }

    #[DataProvider('writes')]
    public function test_write_responses_must_echo_the_request_and_are_never_retried(string $method, array $args): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            $request = Server::request($connection);
            fwrite($connection, Server::frame($request, "\x03\x02\x00\x01", 9));
            fclose($connection);
        });
        $client = $this->client($server);
        $client->read_retries = 1;
        $error = $this->failure(fn () => $client->$method(...$args));
        // A retry would connect to the listener again and time out after the
        // server exits; the original unit mismatch proves it was not retried.
        $this->assertStringContainsString('unit', $error->getMessage());
        $this->assertNull($error->getPrevious());
        $server->finish();
    }

    public static function writes(): array
    {
        return [
            ['fc5', [1, 0, [true]]], ['fc6', [1, 0, [42]]],
            ['fc15', [1, 0, array_fill(0, 8, true)]], ['fc16', [1, 0, [42], ['INT']]],
            ['fc22', [1, 0, 0xff00, 0x00ff]], ['fc23', [1, 0, 1, 1, [42], ['INT']]],
        ];
    }

    #[DataProvider('writes')]
    public function test_accepts_valid_write_and_read_write_replies(string $method, array $args): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            $request = Server::request($connection);
            $function = ord($request[7]);
            $pdu = $function === 23 ? "\x17\x02\x00\x2a" : substr($request, 7, $function === 22 ? 7 : 5);
            fwrite($connection, Server::frame($request, $pdu));
        });
        $result = $this->client($server)->$method(...$args);
        $this->assertSame($method === 'fc23' ? [0, 42] : true, $result);
        $server->finish();
    }

    public function test_rejects_wrong_write_echo_even_when_identity_matches(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            $request = Server::request($connection);
            $reply = $request;
            $reply[11] = chr(ord($reply[11]) ^ 1);
            fwrite($connection, $reply);
        });
        $error = $this->failure(fn () => $this->client($server)->fc6(1, 0, [42]));
        $this->assertStringContainsString('echo', $error->getMessage());
        $server->finish();
    }

    public function test_rtu_over_tcp_reads_fragmented_frames_and_validates_crc(): void
    {
        $server = $this->server(function ($listener) {
            $connection = Server::accept($listener);
            $request = Server::read($connection, 8);
            $codec = new ModbusMaster('127.0.0.1');
            if ($codec->crc16(substr($request, 0, -2)) !== substr($request, -2)) {
                throw new \RuntimeException('Invalid outgoing RTU CRC');
            }
            $reply = "\x01\x03\x02\x00\x2a";
            $reply .= $codec->crc16($reply);
            foreach (str_split($reply) as $byte) {
                fwrite($connection, $byte);
                usleep(1000);
            }
            Server::read($connection, 8);
            fwrite($connection, "\x01\x03\x02\x00\x01\x00\x00");
        });
        $client = $this->client($server, 'RTU_TCP');
        $client->connect();
        $this->assertSame([0, 42], $client->fc3(1, 0, 1));
        $this->assertStringContainsString('CRC', $this->failure(fn () => $client->fc3(1, 0, 1))->getMessage());
        $client->disconnect();
        $server->finish();
    }
}
