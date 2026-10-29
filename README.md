# phpmodbus

Implementation of the basic functionality of the Modbus TCP, UDP and a hack of RTU over TCP based protocol using PHP. 

This is a fork of the original project at https://code.google.com/p/phpmodbus/

> **What's new**
> This fork adds support for running on Mac or Linux (Ubuntu 16.04 only tested), and a completely not proper method for communicating with RTU over TCP devices. (I just hacked off the TCP specific ADU and added the CRC 16 when sending, and check the CRC 16 when receiving, then tack on the TCP specific ADU. )
> Previous fork adds a namespace and fixes issues encountered when porting to PHP 7
> If the ModbusMaster is already connected, all read/write operations will use the open socket and not open/close a new connection.

## Signal-safe communication (1.2.x maintenance branch)

The client uses nonblocking sockets and retries interrupted system calls without
resending the Modbus request. Applications retain ownership of signal handlers:
this library does not install handlers, mask signals, change async dispatch, or
catch process termination. Horizon's graceful-stop handlers can run during an
exchange; its job timeout can still terminate the worker.

TCP responses are assembled using their MBAP length and checked against the
transaction ID, protocol ID, unit, function, and expected byte count or write
echo. Complete stale transactions are skipped within the current deadline.
Malformed frames, EOF, timeouts, and hard socket errors close the connection
before an exception is returned. UDP datagrams and RTU-over-TCP frames (including
CRC) are validated as well. Devices must echo the requested identifiers; devices
that returned mismatched replies accepted by earlier releases now fail explicitly.

```php
require 'vendor/autoload.php';

$client = new PHPModbus\ModbusMaster('192.168.1.10', 'TCP', 502);
$client->timeout_sec = 5;       // Per attempt: connect + write + complete response.
$client->request_delay = 0.55;  // Minimum time since the previous send.
$client->read_retries = 1;     // Optional: one new attempt for TCP FC1-FC4 only.

try {
    $client->connect();       // Reuse the connection across calls.
    $values = $client->fc3(1, 0, 2);
} catch (PHPModbus\DeviceException $error) {
    // A complete, matching device exception. getCode() is the Modbus error code.
} catch (PHPModbus\CommunicationException $error) {
    // Transport or framing failure; the next operation will reconnect.
} finally {
    $client->disconnect();   // Safe to repeat, including after connection failure.
}
```

Both exception types extend `Exception`, so existing `catch (Exception)` handlers
continue to work. A matching device exception is never retried and does not break
an explicitly opened connection. Calling `connect()` repeatedly is harmless.
Without an explicit `connect()`, each operation opens and closes its own connection.

`read_retries` defaults to `0` and accepts only integers `0` or `1`. Enabling it
repeats TCP reads after communication failures on a fresh connection with a new
transaction ID. Opt in only when repeating the device's reads is appropriate
(some registers have read side effects). Writes, including FC23, and UDP/RTU
requests are never automatically replayed. A second communication failure retains the first
communication failure as its previous exception. An explicit `connect()` call
itself is not retried.

`timeout_sec` accepts finite nonnegative seconds, including fractions. It is one
fixed deadline per attempt: signals, partial reads, partial writes, and stale
frames do not restart it. Request pacing is additional and survives interrupted
sleeps. With one retry, allow up to two attempt timeouts plus their request delays;
set worker/job and deployment grace periods accordingly. Hostname lookup still
uses the system resolver; use an IP address when DNS latency must not exceed the
socket deadline. PHP 7.3+ uses a monotonic clock; PHP 7.0-7.2 retains a wall-clock
fallback for compatibility. The client handles one outstanding request at a time.

Run `composer install` and `composer test` on PHP 8.1+ for the PHPUnit suite.
Socket/signal tests use loopback connections and require `pcntl` and `posix` on
Unix. `php tests/Fixtures/compatibility.php` exercises a real interrupted TCP read
without development dependencies on PHP 7.0+. CI runs both the test suite and
legacy-runtime smoke checks.

The older examples below predate Composer and may need their include paths updated.

Implemented features:
 * Modbus master
  * FC1 - Read coils 
  * FC2 - Read input discretes
  * FC3 - Read holding registers 
  * FC4 - Read holding input registers 
  * FC5 - Write single coil 
  * FC6 - Write single register
  * FC15 - Write multiple coils
  * FC16 - Write multiple registers
  * FC23 - Read/Write multiple registers

Example:

```php
require_once dirname(__FILE__) . '/Phpmodbus/ModbusMaster.php'; 

// Modbus master UDP
$modbus = new ModbusMaster("192.168.1.1", "UDP"); 
// Read multiple registers
try {
    $recData = $modbus->readMultipleRegisters(0, 12288, 5); 
}
catch (Exception $e) {
    // Print error information if any
    echo $modbus;
    echo $e;
    exit;
}
// Print data in string format
echo PhpType::bytes2string($recData); 
```

For more see [http://code.google.com/p/phpmodbus/downloads/list documentation] or [http://code.google.com/p/phpmodbus/wiki/FAQ FAQ].

Note: 
 * The PHP extension php_sockets.dll should be enabled (server php.ini file)
