<?php

namespace PHPModbus;

use Psr\Log\LoggerInterface;

interface ModbusMasterInterface
{
    public function setLogger(LoggerInterface $logger);

    public function connect();

    public function disconnect();

    public function readCoils($unitId, $reference, $quantity);

    public function fc1($unitId, $reference, $quantity);

    public function readInputDiscretes($unitId, $reference, $quantity);

    public function fc2($unitId, $reference, $quantity);

    public function readMultipleRegisters($unitId, $reference, $quantity);

    public function fc3($unitId, $reference, $quantity);

    public function readMultipleInputRegisters($unitId, $reference, $quantity);

    public function fc4($unitId, $reference, $quantity);

    public function writeSingleCoil($unitId, $reference, $data);

    public function fc5($unitId, $reference, $data);

    public function writeSingleRegister($unitId, $reference, $data);

    public function fc6($unitId, $reference, $data);

    public function writeMultipleCoils($unitId, $reference, $data);

    public function fc15($unitId, $reference, $data);

    public function writeMultipleRegister($unitId, $reference, $data, $dataTypes);

    public function fc16($unitId, $reference, $data, $dataTypes);

    public function maskWriteRegister($unitId, $reference, $andMask, $orMask);

    public function fc22($unitId, $reference, $andMask, $orMask);

    public function readWriteRegisters($unitId, $referenceRead, $quantity, $referenceWrite, $data, $dataTypes);

    public function fc23($unitId, $referenceRead, $quantity, $referenceWrite, $data, $dataTypes);

    public function crc16($data);
}
