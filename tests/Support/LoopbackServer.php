<?php

namespace Tests\Support;

use RuntimeException;

final class LoopbackServer
{
    public readonly int $port;
    private int $pid;
    private $result;

    public function __construct(callable $serve, string $transport = 'tcp')
    {
        $flags = STREAM_SERVER_BIND | ($transport === 'tcp' ? STREAM_SERVER_LISTEN : 0);
        $listener = stream_socket_server($transport . '://127.0.0.1:0', $code, $message, $flags);
        if ($listener === false) {
            throw new RuntimeException($message, $code);
        }
        $this->port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $parent = getmypid();
        $this->pid = pcntl_fork();
        if ($this->pid === -1) {
            throw new RuntimeException('Could not fork the loopback server');
        }
        if ($this->pid === 0) {
            fclose($pair[0]);
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGALRM, function () { exit(124); });
            pcntl_alarm(8);
            try {
                $serve($listener, $parent);
                fwrite($pair[1], 'OK');
                exit(0);
            } catch (\Throwable $error) {
                fwrite($pair[1], get_class($error) . ': ' . $error->getMessage());
                exit(1);
            }
        }
        fclose($pair[1]);
        fclose($listener);
        $this->result = $pair[0];
        stream_set_timeout($this->result, 10);
    }

    public function finish(): void
    {
        $result = stream_get_contents($this->result);
        do {
            $waited = pcntl_waitpid($this->pid, $status);
        } while ($waited === -1 && pcntl_get_last_error() === PCNTL_EINTR);
        $this->pid = 0;
        fclose($this->result);
        if ($result !== 'OK' || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Loopback server failed: ' . $result . ' (status ' . $status . ')');
        }
    }

    public function stop(): void
    {
        if ($this->pid > 0) {
            posix_kill($this->pid, SIGTERM);
            pcntl_waitpid($this->pid, $status);
            $this->pid = 0;
            fclose($this->result);
        }
    }

    public static function accept($listener)
    {
        $connection = @stream_socket_accept($listener, 3);
        if ($connection === false) {
            throw new RuntimeException('Expected a client connection');
        }
        stream_set_timeout($connection, 3);

        return $connection;
    }

    public static function read($connection, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($connection, $length - strlen($data));
            if ($chunk === '' || $chunk === false) {
                throw new RuntimeException('Expected ' . $length . ' bytes, received ' . strlen($data));
            }
            $data .= $chunk;
        }

        return $data;
    }

    public static function request($connection): string
    {
        $header = self::read($connection, 7);
        return $header . self::read($connection, unpack('n', substr($header, 4, 2))[1] - 1);
    }

    public static function frame(string $request, string $pdu, ?int $unit = null): string
    {
        return substr($request, 0, 4) . pack('n', strlen($pdu) + 1)
            . ($unit === null ? $request[6] : chr($unit)) . $pdu;
    }
}
