<?php

namespace Tests\Unit\ModbusMaster\Fakes;

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
