<?php

namespace Tests\Support {
    /** Inject rare syscall outcomes while all successful I/O still uses real sockets. */
    final class SocketFaults
    {
        public static array $writes = [];
        public static array $reads = [];
        public static array $selects = [];
        public static ?int $socketError = null;
        public static ?int $globalError = null;
        public static bool $blockWrites = false;

        public static function reset(): void
        {
            self::$writes = self::$reads = self::$selects = [];
            self::$socketError = self::$globalError = null;
            self::$blockWrites = false;
        }
    }
}

namespace PHPModbus {
    use Tests\Support\SocketFaults as Faults;

    function socket_write($socket, $data, $length = null)
    {
        $fault = array_shift(Faults::$writes);
        if (isset($fault['error']) || Faults::$blockWrites) {
            Faults::$socketError = $fault['error'] ?? SOCKET_EAGAIN;
            return false;
        }
        return \socket_write($socket, $data, $fault['limit'] ?? $length ?? strlen($data));
    }

    function socket_recv($socket, &$data, $length, $flags)
    {
        if (Faults::$reads !== []) {
            Faults::$socketError = array_shift(Faults::$reads);
            return false;
        }
        return \socket_recv($socket, $data, $length, $flags);
    }

    function socket_select(&$read, &$write, &$except, $seconds, $microseconds = 0)
    {
        if (Faults::$selects !== []) {
            Faults::$globalError = array_shift(Faults::$selects);
            trigger_error('Injected socket_select failure', E_USER_WARNING);
            return false;
        }
        return \socket_select($read, $write, $except, $seconds, $microseconds);
    }

    function socket_last_error($socket = null)
    {
        return $socket === null ? (Faults::$globalError ?? \socket_last_error())
            : (Faults::$socketError ?? \socket_last_error($socket));
    }

    function socket_clear_error($socket = null)
    {
        if ($socket === null) {
            Faults::$globalError = null;
            \socket_clear_error();
        } else {
            Faults::$socketError = null;
            \socket_clear_error($socket);
        }
    }
}
