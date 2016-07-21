# phpmodbus

Implementation of the basic functionality of the Modbus TCP and UDP based protocol using PHP. 

This is a fork of the original project at https://code.google.com/p/phpmodbus/

> **What's new**
> 
> This fork adds a namespace and fixes issues encountered when porting to PHP 7
> If the ModbusMaster is already connected, all read/write operations will use the open socket and not open/close a new connection.

**WARNING: Everything except the actual code in this repo may be broken and outdated.**

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
