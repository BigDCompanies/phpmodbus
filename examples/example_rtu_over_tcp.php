<?php

require_once dirname(__FILE__) . '/../src/ModbusMaster.php';
require_once dirname(__FILE__) . '/../src/IecType.php';
use PHPModbus\ModbusMaster;

// Create Modbus object
$modbus = new ModbusMaster("10.0.0.10", "RTU_TCP", 9503);
$modbus->debug = true;
$modbus->request_delay = 0.25;
$modbus->connect();

while(1) {

    try {
        $recData = $modbus->fc3(1, 7020, 2);
    }
    catch (Exception $e) {
        // Print error information if any
        echo $modbus;
        echo $e;
        exit;
    }

    // Print read data
    echo "</br>Data:</br>";
    var_dump($recData); 
    echo "</br>";

    sleep(3);
}
