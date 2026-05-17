<?php

namespace Tests\Unit\ModbusMaster\Fakes;

use Psr\Log\AbstractLogger;

class ArrayLogger extends AbstractLogger
{
    public $records = array();

    public function log($level, $message, array $context = array())
    {
        $this->records[] = array($level, $message, $context);
    }
}
