<?php

require_once dirname(__FILE__) . '/../src/ModbusMaster.php';
require_once dirname(__FILE__) . '/../src/IecType.php';
use PHPModbus\ModbusMaster;

// Create Modbus object
$modbus = new ModbusMaster("10.0.0.101", "TCP", 502);
$modbus->debug = true;
$modbus->request_delay = 0.25;
$modbus->connect();

while(1) {

    try {
        $recData = $modbus->readInputDiscretes(0, 16, 8);
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

    try {
        $recData = $modbus->readCoils(0, 0, 4);
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
}
