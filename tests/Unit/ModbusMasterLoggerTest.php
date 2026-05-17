<?php

namespace Tests\Unit;

use PHPModbus\ModbusMaster;
use Psr\Log\AbstractLogger;

class ArrayLogger extends AbstractLogger
{
    public $records = array();

    public function log($level, $message, array $context = array())
    {
        $this->records[] = array($level, $message, $context);
    }
}

it('uses a configured PSR logger for debug output', function () {
    $master = new ModbusMaster('127.0.0.1');
    $logger = new ArrayLogger();
    $reflection = new \ReflectionClass($master);
    $method = $reflection->getMethod('log');

    $method->setAccessible(true);
    $master->debug = true;

    $this->assertSame($master, $master->setLogger($logger));

    $method->invoke($master, 'Connected');

    $this->assertSame(array(
        array('debug', 'Connected', array()),
    ), $logger->records);
});

it('uses the echo logger by default for debug output', function () {
    $master = new ModbusMaster('127.0.0.1');
    $reflection = new \ReflectionClass($master);
    $method = $reflection->getMethod('log');

    $method->setAccessible(true);
    $master->debug = true;

    ob_start();
    $method->invoke($master, 'Connected');
    $output = ob_get_clean();

    $this->assertMatchesRegularExpression('/ - Connected\n$/', $output);
});
