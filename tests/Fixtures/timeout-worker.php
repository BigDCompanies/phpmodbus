<?php

require __DIR__ . '/../../vendor/autoload.php';

pcntl_async_signals(true);
pcntl_signal(SIGALRM, function () { exit(73); });
pcntl_alarm(1);
$client = new PHPModbus\ModbusMaster('127.0.0.1', 'TCP', (int) $argv[1]);
$client->timeout_sec = 4;
$client->read_retries = 1;
$client->fc3(1, 0, 1);
exit(1);
