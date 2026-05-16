<?php

namespace Tests\Unit {
    use PHPModbus\ModbusMaster;

    class ModbusMasterSocketFake
    {
        public static $writtenPacket = '';

        public static $responseTransactionId = '';

        public static function reset()
        {
            self::$writtenPacket = '';
            self::$responseTransactionId = '';
        }
    }

    beforeEach(function () {
        ModbusMasterSocketFake::reset();
    });

    it('includes a transaction ID in Modbus requests', function () {
        $packet = buildReadCoilsRequestPacket();

        $this->assertSame(12, strlen($packet));
        $this->assertSame(2, strlen(substr($packet, 0, 2)));
        $this->assertSame("\x00\x00", substr($packet, 2, 2));
        $this->assertSame("\x00\x06", substr($packet, 4, 2));
    });

    it('throws when the response transaction ID does not match the request transaction ID', function () {
        $master = connectedModbusMaster();
        ModbusMasterSocketFake::$responseTransactionId = 'mismatch-request';

        $master->readCoils(1, 0, 1);
    })->throws(\Exception::class, 'Modbus transaction ID mismatch');

    it('accepts responses with a matching transaction ID', function () {
        $master = connectedModbusMaster();
        ModbusMasterSocketFake::$responseTransactionId = 'match-request';

        $data = $master->readCoils(1, 0, 1);

        $this->assertSame([true], $data);
    });

    function buildReadCoilsRequestPacket()
    {
        $master = new ModbusMaster('127.0.0.1', 'TCP');
        $reflection = new \ReflectionClass($master);
        $method = $reflection->getMethod('readCoilsPacketBuilder');
        $method->setAccessible(true);

        return $method->invoke($master, 1, 0, 1);
    }

    function connectedModbusMaster()
    {
        $master = new ModbusMaster('127.0.0.1', 'TCP');
        $reflection = new \ReflectionClass($master);

        $connected = $reflection->getProperty('connected');
        $connected->setAccessible(true);
        $connected->setValue($master, true);

        $sock = $reflection->getProperty('sock');
        $sock->setAccessible(true);
        $sock->setValue($master, 'fake-socket');

        return $master;
    }
}

// PHP resolves unqualified function calls inside ModbusMaster against this namespace first,
// so these shims intercept socket I/O without opening a real socket during the test.
namespace PHPModbus {
    use Tests\Unit\ModbusMasterSocketFake;

    function socket_write($socket, $packet, $length)
    {
        ModbusMasterSocketFake::$writtenPacket = $packet;

        return $length;
    }

    function socket_set_nonblock($socket)
    {
        return true;
    }

    function socket_select(&$readsocks, &$writesocks, &$exceptsocks, $seconds, $microseconds = 0)
    {
        return 1;
    }

    function socket_close($socket)
    {
        return null;
    }

    function socket_recv($socket, &$buffer, $length, $flags)
    {
        $transactionId = ModbusMasterSocketFake::$responseTransactionId;

        if ($transactionId === 'match-request') {
            $transactionId = substr(ModbusMasterSocketFake::$writtenPacket, 0, 2);
        }

        if ($transactionId === 'mismatch-request') {
            $transactionId = substr(ModbusMasterSocketFake::$writtenPacket, 0, 2) === "\x12\x34"
                ? "\x56\x78"
                : "\x12\x34";
        }

        $buffer = $transactionId . "\x00\x00\x00\x04\x01\x01\x01\x01";

        return strlen($buffer);
    }
}
