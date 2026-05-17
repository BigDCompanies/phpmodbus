<?php

if (!extension_loaded('pcntl') || !extension_loaded('sockets')) {
    fwrite(STDERR, "pcntl and sockets extensions are required.\n");
    exit(2);
}

$signal = getenv('SIGNAL') === 'SIGTERM' ? SIGTERM : SIGUSR1;
pcntl_async_signals(true);
pcntl_signal($signal, function () {
    // Empty async handler mirrors queue workers that handle and continue.
});

$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    $error = socket_last_error();
    fwrite(STDERR, "socket_create_pair() failed [$error]: " . socket_strerror($error) . "\n");
    exit(1);
}

$child = pcntl_fork();
if ($child === -1) {
    fwrite(STDERR, "pcntl_fork() failed.\n");
    exit(1);
}

if ($child === 0) {
    usleep(100000);
    posix_kill(posix_getppid(), $signal);
    exit(0);
}

$readsocks = [$pair[0]];
$writesocks = null;
$exceptsocks = null;
$selected = @socket_select($readsocks, $writesocks, $exceptsocks, 5, 0);
$error = socket_last_error($pair[0]) ?: socket_last_error();

pcntl_waitpid($child, $status);
socket_close($pair[0]);
socket_close($pair[1]);

if ($selected !== false || ($error !== SOCKET_EINTR && $error !== 4)) {
    fwrite(STDERR, "Expected socket_select() false with EINTR, got selected=" . var_export($selected, true) . " error=$error.\n");
    exit(1);
}

echo "socket_select() interrupted by signal with EINTR [$error].\n";
