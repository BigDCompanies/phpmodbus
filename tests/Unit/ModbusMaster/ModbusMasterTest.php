<?php

namespace Tests\Unit\ModbusMaster;

use PHPModbus\ModbusMaster;
use Tests\Unit\ModbusMaster\Fakes\ArrayLogger;
use Tests\Unit\ModbusMaster\Fakes\ModbusMasterSocketFake;

require_once __DIR__ . '/SocketFunctions.php';

beforeEach(function () {
    ModbusMasterSocketFake::reset();
});

it('uses a configured PSR logger for debug output', function () {
    $master = new ModbusMaster('127.0.0.1');
    $logger = new ArrayLogger();
    $method = modbusMasterMethod($master, 'log');

    $master->debug = true;

    expect($master->setLogger($logger))->toBe($master);

    $method->invoke($master, 'Connected');

    expect($logger->records)->toBe([
        ['debug', 'Connected', []],
    ]);
});

it('uses the echo logger by default for debug output', function () {
    $master = new ModbusMaster('127.0.0.1');
    $method = modbusMasterMethod($master, 'log');

    $master->debug = true;

    ob_start();
    $method->invoke($master, 'Connected');
    $output = ob_get_clean();

    expect($output)->toMatch('/ - Connected\n$/');
});

it('includes a transaction ID in Modbus requests', function () {
    $packet = buildReadCoilsRequestPacket();

    expect(strlen($packet))->toBe(12)
        ->and(strlen(substr($packet, 0, 2)))->toBe(2)
        ->and(substr($packet, 2, 2))->toBe("\x00\x00")
        ->and(substr($packet, 4, 2))->toBe("\x00\x06");
});

it('throws when the response transaction ID does not match the request transaction ID', function () {
    $master = connectedModbusMaster();
    ModbusMasterSocketFake::$responseTransactionId = 'mismatch-request';

    $master->readCoils(1, 0, 1);
})->throws(\Exception::class, 'Modbus transaction ID mismatch');

it('accepts responses with a matching transaction ID', function () {
    $master = connectedModbusMaster();
    ModbusMasterSocketFake::$responseTransactionId = 'match-request';

    expect($master->readCoils(1, 0, 1))->toBe([true]);
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

    expect($first)->toBe([true])
        ->and($second)->toBe([true])
        ->and(ModbusMasterSocketFake::$receivedTransactionIds)->toBe([
            substr(ModbusMasterSocketFake::$writtenPackets[0], 0, 2),
            substr(ModbusMasterSocketFake::$writtenPackets[1], 0, 2),
        ]);
});

it('reads TCP responses using the MBAP header length', function () {
    $master = connectedModbusMaster();
    ModbusMasterSocketFake::$responseTransactionId = 'match-request';

    $master->readCoils(1, 0, 1);

    expect(ModbusMasterSocketFake::$readLengths)->toBe([7, 3]);
});

function buildReadCoilsRequestPacket()
{
    $master = new ModbusMaster('127.0.0.1', 'TCP');
    $method = modbusMasterMethod($master, 'readCoilsPacketBuilder');

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

function modbusMasterMethod(ModbusMaster $master, string $methodName)
{
    $reflection = new \ReflectionClass($master);
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(true);

    return $method;
}
