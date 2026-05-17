<?php

namespace Tests\Unit {
    use PHPModbus\ModbusMaster;

    class ModbusMasterSocketFake
    {
        public static $writtenPacket = '';

        public static $writtenPackets = [];

        public static $responseTransactionId = '';

        public static $readResults = [];

        public static $readCalls = 0;

        public static $readLengths = [];

        public static $readBuffer = '';

        public static $timedOut = false;

        public static $receivedTransactionIds = [];

        public static function reset()
        {
            self::$writtenPacket = '';
            self::$writtenPackets = [];
            self::$responseTransactionId = '';
            self::$readResults = [];
            self::$readCalls = 0;
            self::$readLengths = [];
            self::$readBuffer = '';
            self::$timedOut = false;
            self::$receivedTransactionIds = [];
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

    it('throws when the stream read times out', function () {
        $master = connectedModbusMaster();
        ModbusMasterSocketFake::$readResults = [''];
        ModbusMasterSocketFake::$timedOut = true;

        $master->readCoils(1, 0, 1);
    })->throws(\Exception::class, 'Watchdog time expired');

    it('matches transaction IDs across consecutive requests', function () {
        $master = connectedModbusMaster();
        ModbusMasterSocketFake::$responseTransactionId = 'match-request';

        $first = $master->readCoils(1, 0, 1);
        $second = $master->readCoils(1, 0, 1);

        $this->assertSame([true], $first);
        $this->assertSame([true], $second);
        $this->assertSame([
            substr(ModbusMasterSocketFake::$writtenPackets[0], 0, 2),
            substr(ModbusMasterSocketFake::$writtenPackets[1], 0, 2),
        ], ModbusMasterSocketFake::$receivedTransactionIds);
    });

    it('reads TCP responses using the MBAP header length', function () {
        $master = connectedModbusMaster();
        ModbusMasterSocketFake::$responseTransactionId = 'match-request';

        $master->readCoils(1, 0, 1);

        $this->assertSame([7, 3], ModbusMasterSocketFake::$readLengths);
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

    function fwrite($stream, $packet, $length = null)
    {
        ModbusMasterSocketFake::$writtenPacket = $packet;
        ModbusMasterSocketFake::$writtenPackets[] = $packet;

        return $length === null ? strlen($packet) : $length;
    }

    function stream_set_timeout($stream, $seconds, $microseconds = 0)
    {
        return true;
    }

    function stream_get_meta_data($stream)
    {
        return ['timed_out' => ModbusMasterSocketFake::$timedOut];
    }

    function fclose($stream)
    {
        return true;
    }

    function fread($stream, $length)
    {
        ModbusMasterSocketFake::$readCalls++;
        ModbusMasterSocketFake::$readLengths[] = $length;

        if (ModbusMasterSocketFake::$readResults) {
            return array_shift(ModbusMasterSocketFake::$readResults);
        }

        if (ModbusMasterSocketFake::$readBuffer === '') {
            $transactionId = ModbusMasterSocketFake::$responseTransactionId;

            if ($transactionId === 'match-request') {
                $transactionId = substr(ModbusMasterSocketFake::$writtenPacket, 0, 2);
            }

            if ($transactionId === 'mismatch-request') {
                $transactionId = substr(ModbusMasterSocketFake::$writtenPacket, 0, 2) === "\x12\x34"
                    ? "\x56\x78"
                    : "\x12\x34";
            }

            ModbusMasterSocketFake::$receivedTransactionIds[] = $transactionId;
            ModbusMasterSocketFake::$readBuffer = $transactionId . "\x00\x00\x00\x04\x01\x01\x01\x01";
        }

        $chunk = substr(ModbusMasterSocketFake::$readBuffer, 0, $length);
        ModbusMasterSocketFake::$readBuffer = substr(ModbusMasterSocketFake::$readBuffer, $length);

        return $chunk;
    }
}
