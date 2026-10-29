<?php

// Runtime smoke test independent of PHPUnit's PHP >=8.1 requirement.
spl_autoload_register(function ($class) {
    if (strpos($class, 'PHPModbus\\') === 0) {
        require __DIR__ . '/../../src/' . substr($class, strlen('PHPModbus\\')) . '.php';
    }
});
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
$server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
$port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
pcntl_async_signals_compat();
$handled = false;
pcntl_signal(SIGTERM, function () use (&$handled) { $handled = true; });
$parent = getmypid();
$pid = pcntl_fork();
if ($pid === 0) {
    $connection = stream_socket_accept($server, 3);
    stream_set_timeout($connection, 3);
    $request = '';
    while (strlen($request) < 12) {
        $request .= fread($connection, 12 - strlen($request));
    }
    usleep(50000);
    posix_kill($parent, SIGTERM);
    usleep(50000);
    fwrite($connection, substr($request, 0, 4) . "\x00\x05\x01\x03\x02\x00\x2a");
    exit(0);
}
fclose($server);
$client = new PHPModbus\ModbusMaster('127.0.0.1', 'TCP', $port);
$client->timeout_sec = 1;
$result = $client->fc3(1, 0, 1);
pcntl_signal_dispatch();
pcntl_waitpid($pid, $status);
if ($result !== array(0, 42) || !$handled || pcntl_wexitstatus($status) !== 0) {
    throw new RuntimeException('Signal-safe TCP read failed');
}
$client->disconnect();
$client->disconnect();
echo 'Signal-safe TCP read passed on PHP ', PHP_VERSION, "\n";

function pcntl_async_signals_compat()
{
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
    }
}
