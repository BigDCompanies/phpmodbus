<?php

namespace PHPModbus;

use Tests\Unit\ModbusMaster\Fakes\ModbusMasterSocketFake;

// PHP resolves unqualified function calls inside ModbusMaster against this namespace first,
// so these shims intercept socket I/O without opening a real socket during the test.
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
