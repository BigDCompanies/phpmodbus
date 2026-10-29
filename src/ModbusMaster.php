<?php

namespace PHPModbus;

/**
 * Phpmodbus Copyright (c) 2004, 2013 Jan Krakora
 *
 * This source file is subject to the "PhpModbus license" that is bundled
 * with this package in the file license.txt.
 *
 *
 * @copyright  Copyright (c) 2004, 2013 Jan Krakora
 * @license    PhpModbus license
 * @category   Phpmodbus
 * @tutorial   Phpmodbus.pkg
 * @package    Phpmodbus
 * @version    $id$
 *
 */


/**
 * ModbusMaster
 *
 * This class deals with the MODBUS master
 *
 * Implemented MODBUS master functions:
 *   - FC  1: read coils
 *   - FC  2: read input discretes
 *   - FC  3: read multiple registers
 *   - FC  4: read multiple input registers
 *   - FC  5: write single coil
 *   - FC  6: write single register
 *   - FC 15: write multiple coils
 *   - FC 16: write multiple registers
 *   - FC 22: mask write register
 *   - FC 23: read write registers
 *
 * @author     Jan Krakora
 * @copyright  Copyright (c) 2004, 2013 Jan Krakora
 * @package    Phpmodbus
 *
 */
class ModbusMaster
{
	private $sock = null;

    private $keepConnected = false;

    private $transactionId = 0;

    /** @var int Opt-in retries (0 or 1) for TCP FC1-FC4 communication failures. */
    public $read_retries = 0;

	public $host = "192.168.1.1";

	public $port = 502;

	public $client = "";

	public $client_port = 502;

	public $status;

	public $timeout_sec = 5; // Timeout 5 sec

	public $endianness = 0; // Endianness codding (little endian == 0, big endian == 1)

	public $socket_protocol = "UDP"; // Socket protocol (TCP, UDP, RTU_TCP)

	public $debug = false;

    /**
     * @var float $request_delay seconds to delay (or fraction there of) between requests. Needed for some older controllers.
     */
	public $request_delay = 0;

    /**
     * @var bool true if socket is connected
     */
    private $connected = false;

    /**
     * @var float Time of the last sent bytes, used for the request delay.
     */
    private $last_request = 0;


	/**
	 * ModbusMaster
	 *
	 * This is the constructor that defines {@link $host} IP address of the object.
	 *
	 * @param String $host     An IP address of a Modbus TCP device. E.g. "192.168.1.1"
	 * @param String $protocol Socket protocol (TCP, UDP, RTU_TCP)
	 * @param Integer $port    Port number
	 */
	public function __construct($host, $protocol="UDP", $port=502)
	{
		$this->socket_protocol = $protocol;
		$this->host = $host;
		$this->port = $port;
	}

	/**
	 * __toString
	 *
	 * Magic method
	 */
	public function __toString()
	{
		return $this->status;
	}

	public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Connect explicitly to reuse the connection across calls, including recovery
     * after a failed exchange. Automatic connections last for one operation.
     */
    public function connect()
    {
        $this->openSocket($this->deadline());
        $this->keepConnected = true;

        return true;
    }

    private function openSocket($deadline)
    {
        if ($this->connected) {
            return;
        }
        if (!in_array($this->socket_protocol, array('TCP', 'UDP', 'RTU_TCP'), true)) {
            throw new \InvalidArgumentException("Unknown socket protocol, should be 'TCP', 'UDP' or 'RTU_TCP'");
        }

        try {
            $udp = $this->socket_protocol === 'UDP';
            $socket = @socket_create(AF_INET, $udp ? SOCK_DGRAM : SOCK_STREAM, $udp ? SOL_UDP : SOL_TCP);
            if ($socket === false) {
                throw $this->socketError('create', socket_last_error());
            }
            $this->sock = $socket;
            if (!@socket_set_nonblock($socket)) {
                throw $this->socketError('set nonblocking mode', socket_last_error($socket));
            }
            if (strlen($this->client) > 0 && !@socket_bind($socket, $this->client, $this->client_port)) {
                throw $this->socketError('bind', socket_last_error($socket));
            }

            socket_clear_error($socket);
            if (!@socket_connect($socket, $this->host, $this->port)) {
                $error = socket_last_error($socket);
                if ($error !== SOCKET_EISCONN && !$this->connectPending($error)) {
                    throw $this->socketError('connect', $error);
                }
                while ($error !== SOCKET_EISCONN) {
                    $this->waitForSocket(false, $deadline);
                    $error = @socket_get_option($socket, SOL_SOCKET, SO_ERROR);
                    if ($error === 0) {
                        break;
                    }
                    if ($error === false || !$this->connectPending($error)) {
                        throw $this->socketError('connect', $error === false ? socket_last_error($socket) : $error);
                    }
                }
            }
            $this->connected = true;
            $this->log('Connected');
        } catch (\Throwable $error) {
            $this->closeSocket();
            throw $error;
        }
    }

    public function disconnect()
    {
        $this->keepConnected = false;
        $this->closeSocket();
    }

    private function closeSocket()
    {
        $socket = $this->sock;
        // Clear ownership before closing: failed connect, cleanup and destruction
        // must never close the same PHP 8 Socket object twice.
        $this->sock = null;
        $this->connected = false;
        if ($socket !== null) {
            socket_close($socket);
            $this->log('Disconnected');
        }
    }

    /**
     * One outstanding request per client. Only communication failures can be
     * retried; application exceptions (including signal handlers) propagate.
     */
    private function exchange($packet)
    {
        $function = ord($packet[7]);
        $retryable = $this->socket_protocol === 'TCP' && $function >= 1 && $function <= 4;
        if ($retryable && $this->read_retries !== 0 && $this->read_retries !== 1) {
            throw new \InvalidArgumentException('read_retries must be 0 or 1');
        }
        $retries = $retryable ? $this->read_retries : 0;
        $firstFailure = null;

        try {
            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                try {
                    if ($attempt > 0) {
                        $packet = $this->nextTransactionId() . substr($packet, 2);
                    }
                    $this->waitForTurnaround();
                    $deadline = $this->deadline();
                    $this->openSocket($deadline);
                    $this->send($packet, $deadline);
                    $response = $this->receive($packet, $deadline);
                    $this->validateResponse($packet, $response);
                    $this->responseCode($response);

                    return $response;
                } catch (CommunicationException $error) {
                    $this->closeSocket();
                    if ($attempt === $retries) {
                        if ($firstFailure !== null) {
                            throw new CommunicationException($error->getMessage(), $error->getCode(), $firstFailure);
                        }
                        throw $error;
                    }
                    $firstFailure = $error;
                } catch (DeviceException $error) {
                    // A matched device error is a complete response. The
                    // connection is still synchronized and is safe to reuse.
                    throw $error;
                } catch (\Throwable $error) {
                    $this->closeSocket();
                    throw $error;
                }
            }
        } finally {
            if (!$this->keepConnected) {
                $this->closeSocket();
            }
        }
    }

    private function send($packet, $deadline)
    {
        if ($this->socket_protocol === 'RTU_TCP') {
            $packet = substr($packet, 6);
            $packet .= $this->crc16($packet);
        }
        $this->log('Sending: ' . $this->printPacket($packet));
        $offset = 0;
        while ($offset < strlen($packet)) {
            $this->waitForSocket(false, $deadline);
            socket_clear_error($this->sock);
            $sent = @socket_write($this->sock, substr($packet, $offset));
            if ($sent === false) {
                $error = socket_last_error($this->sock);
                if ($this->interruptedOrWouldBlock($error)) {
                    continue;
                }
                throw $this->socketError('write', $error);
            }
            if ($sent === 0 || ($this->socket_protocol === 'UDP' && $sent !== strlen($packet))) {
                throw new CommunicationException('Modbus request could not be sent completely');
            }
            $offset += $sent;
            $this->last_request = $this->now();
        }
    }

    private function receive($request, $deadline)
    {
        if ($this->socket_protocol === 'RTU_TCP') {
            return $this->readRtuResponse($request, $deadline);
        }

        do {
            if ($this->socket_protocol === 'TCP') {
                $header = $this->readExact(7, $deadline);
                $length = $this->responseLength($header);
                $response = $header . $this->readExact($length - 1, $deadline);
            } else {
                // One recv per datagram; an extra byte detects oversized packets.
                $response = $this->readChunk(261, $deadline);
                $length = $this->responseLength($response);
                if (strlen($response) !== 6 + $length) {
                    throw new CommunicationException('Modbus response length does not match its frame');
                }
            }
            // Only discard complete, bounded frames. Never scan arbitrary bytes
            // for a plausible header or let stale frames extend the deadline.
        } while (substr($response, 0, 2) !== substr($request, 0, 2));

        return $response;
    }

    private function responseLength($header)
    {
        if (strlen($header) < 7 || substr($header, 2, 2) !== "\x00\x00") {
            throw new CommunicationException('Modbus response has an invalid MBAP header');
        }
        $length = unpack('n', substr($header, 4, 2))[1];
        if ($length < 2 || $length > 254) {
            throw new CommunicationException('Modbus response length is invalid');
        }

        return $length;
    }

    private function readExact($length, $deadline)
    {
        $data = '';
        while (strlen($data) < $length) {
            $data .= $this->readChunk($length - strlen($data), $deadline);
        }

        return $data;
    }

    private function readChunk($length, $deadline)
    {
        while (true) {
            $this->waitForSocket(true, $deadline);
            socket_clear_error($this->sock);
            $data = '';
            $received = @socket_recv($this->sock, $data, $length, 0);
            if ($received === false) {
                $error = socket_last_error($this->sock);
                if ($this->interruptedOrWouldBlock($error)) {
                    continue;
                }
                throw $this->socketError('read', $error);
            }
            if ($received === 0) {
                throw new CommunicationException('Modbus connection closed before a complete response was received');
            }

            return $data;
        }
    }

    private function readRtuResponse($request, $deadline)
    {
        $frame = $this->readExact(2, $deadline);
        $function = ord($frame[1]);
        if ($function & 0x80) {
            $frame .= $this->readExact(3, $deadline);
        } elseif (in_array($function, array(1, 2, 3, 4, 23), true)) {
            $frame .= $this->readExact(1, $deadline);
            $bytes = ord($frame[2]);
            if ($bytes > 250) {
                throw new CommunicationException('Modbus RTU response length is invalid');
            }
            $frame .= $this->readExact($bytes + 2, $deadline);
        } elseif (in_array($function, array(5, 6, 15, 16, 22), true)) {
            $frame .= $this->readExact($function === 22 ? 8 : 6, $deadline);
        } else {
            throw new CommunicationException('Modbus RTU response function is invalid');
        }
        $payload = substr($frame, 0, -2);
        if ($this->crc16($payload) !== substr($frame, -2)) {
            throw new CommunicationException('Modbus RTU response CRC does not match');
        }

        // The existing parsers consume an MBAP-shaped frame for every transport.
        return substr($request, 0, 4) . pack('n', strlen($payload)) . $payload;
    }

    private function validateResponse($request, $response)
    {
        if (strlen($response) < 8 || $response[6] !== $request[6]) {
            throw new CommunicationException('Modbus response unit does not match the request');
        }
        $function = ord($request[7]);
        if (ord($response[7]) === ($function | 0x80)) {
            if (strlen($response) !== 9) {
                throw new CommunicationException('Modbus exception response length is invalid');
            }
            return;
        }
        if ($response[7] !== $request[7]) {
            throw new CommunicationException('Modbus response function does not match the request');
        }
        if (in_array($function, array(1, 2, 3, 4, 23), true)) {
            $quantity = unpack('n', substr($request, 10, 2))[1];
            $bytes = $function <= 2 ? (int) ceil($quantity / 8) : $quantity * 2;
            if (strlen($response) !== 9 + $bytes || ord($response[8]) !== $bytes) {
                throw new CommunicationException('Modbus response byte count does not match the request');
            }
        } else {
            $expected = in_array($function, array(15, 16), true) ? substr($request, 7, 5) : substr($request, 7);
            if (substr($response, 7) !== $expected) {
                throw new CommunicationException('Modbus write response does not echo the request');
            }
        }
    }

    private function waitForSocket($reading, $deadline)
    {
        while (true) {
            $remaining = $deadline - $this->now();
            if ($remaining <= 0) {
                throw new CommunicationException("Watchdog time expired [ $this->timeout_sec sec ] waiting for Modbus at $this->host");
            }
            // select mutates these arrays, so recreate them after every interrupt.
            $read = $reading ? array($this->sock) : null;
            $write = $reading ? null : array($this->sock);
            $except = null;
            $seconds = (int) floor($remaining);
            $microseconds = (int) floor(($remaining - $seconds) * 1000000);
            socket_clear_error();
            $ready = @socket_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === false) {
                // select stores errno on the extension, not the individual socket.
                $error = socket_last_error();
                if ($error === SOCKET_EINTR) {
                    continue;
                }
                throw $this->socketError('select', $error);
            }
            if ($ready > 0) {
                return;
            }
        }
    }

    private function interruptedOrWouldBlock($error)
    {
        return $error === SOCKET_EINTR || $error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK;
    }

    private function connectPending($error)
    {
        return $this->interruptedOrWouldBlock($error) || $error === SOCKET_EINPROGRESS || $error === SOCKET_EALREADY;
    }

    private function socketError($operation, $error)
    {
        return new CommunicationException('Modbus socket ' . $operation . ' failed: ' . socket_strerror($error), $error);
    }

    private function deadline()
    {
        if (!is_numeric($this->timeout_sec) || !is_finite((float) $this->timeout_sec) || $this->timeout_sec < 0) {
            throw new \InvalidArgumentException('timeout_sec must be a finite nonnegative number');
        }

        return $this->now() + (float) $this->timeout_sec;
    }

    private function waitForTurnaround()
    {
        if (!is_numeric($this->request_delay) || !is_finite((float) $this->request_delay) || $this->request_delay < 0) {
            throw new \InvalidArgumentException('request_delay must be a finite nonnegative number');
        }
        $until = $this->last_request + (float) $this->request_delay;
        while (($remaining = $until - $this->now()) > 0) {
            usleep((int) min(1000000, ceil($remaining * 1000000)));
        }
    }

    private function now()
    {
        // hrtime is monotonic on PHP 7.3+. Keep the package's PHP 7.0-7.2 API
        // compatibility, where only the wall clock is available without an extension.
        return function_exists('hrtime') ? hrtime(true) / 1000000000 : microtime(true);
    }

    private function nextTransactionId()
    {
        $this->transactionId = ($this->transactionId + 1) & 0xffff;

        return pack('n', $this->transactionId);
    }

	/**
	 * responseCode
	 *
	 * Check the Modbus response code
	 *
	 * @param string $packet
	 * @return bool
	 * @throws DeviceException
	 */
	private function responseCode($packet)
	{
		if ((ord($packet[7]) & 0x80) > 0) {
			// failure code
			$failure_code = ord($packet[8]);
			// failure code strings
			$failures = array(
				0x01 => "ILLEGAL FUNCTION",
				0x02 => "ILLEGAL DATA ADDRESS",
				0x03 => "ILLEGAL DATA VALUE",
				0x04 => "SLAVE DEVICE FAILURE",
				0x05 => "ACKNOWLEDGE",
				0x06 => "SLAVE DEVICE BUSY",
				0x08 => "MEMORY PARITY ERROR",
				0x0A => "GATEWAY PATH UNAVAILABLE",
				0x0B => "GATEWAY TARGET DEVICE FAILED TO RESPOND"
			);
			// get failure string
			if (key_exists($failure_code, $failures)) {
				$failure_str = $failures[$failure_code];
			} else {
				$failure_str = "UNDEFINED FAILURE CODE";
			}
			// exception response
			throw new DeviceException("Modbus response error code: $failure_code ($failure_str)", $failure_code);
		} else {
			$this->log( "Modbus response error code: NOERROR" );
			return true;
		}
	}

	/**
	 * readCoils
	 *
	 * Modbus function FC 1(0x01) - Read Coils
	 *
	 * Reads {@link $quantity} of Coils (boolean) from reference
	 * {@link $reference} of a memory of a Modbus device given by
	 * {@link $unitId}.
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return bool[]
	 */
	public function readCoils($unitId, $reference, $quantity)
	{
		$this->log( "readCoils: START" );
		// send FC 1
		$packet = $this->readCoilsPacketBuilder($unitId, $reference, $quantity);
		$rpacket = $this->exchange($packet);
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$receivedData = $this->readCoilsParser($rpacket, $quantity);
		$this->log( "readCoils: DONE" );
		// return
		return $receivedData;
	}

	/**
	 * fc1
	 *
	 * Alias to {@link readCoils} method
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return bool[]
	 */
	public function fc1($unitId, $reference, $quantity)
	{
		return $this->readCoils($unitId, $reference, $quantity);
	}

	/**
	 * readCoilsPacketBuilder
	 *
	 * FC1 packet builder - read coils
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return string
	 */
	private function readCoilsPacketBuilder($unitId, $reference, $quantity)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(1);              // FC 1 = 1(0x01)
		// build body - read section
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$buffer2 .= IecType::iecINT($quantity);       // quantity
		$dataLen += 5;
		// build header
		$buffer3 = '';
		$buffer3 .= $this->nextTransactionId();   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID
		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * readCoilsParser
	 *
	 * FC 1 response parser
	 *
	 * @param string $packet
	 * @param int    $quantity
	 * @return bool[]
	 */
	private function readCoilsParser($packet, $quantity)
	{
		$data = array();
		// get data from stream
		for ($i = 0; $i < ord($packet[8]); $i++) {
			$data[$i] = ord($packet[9 + $i]);
		}
		// get bool values to array
		$data_boolean_array = array();
		$di = 0;
		foreach ($data as $value) {
			for ($i = 0; $i < 8; $i++) {
				if ($di == $quantity) {
					continue;
				}
				// get boolean value
				$v = ($value >> $i) & 0x01;
				// build boolean array
				if ($v == 0) {
					$data_boolean_array[] = false;
				} else {
					$data_boolean_array[] = true;
				}
				$di++;
			}
		}
		return $data_boolean_array;
	}

	/**
	 * readInputDiscretes
	 *
	 * Modbus function FC 2(0x02) - Read Input Discretes
	 *
	 * Reads {@link $quantity} of Inputs (boolean) from reference
	 * {@link $reference} of a memory of a Modbus device given by
	 * {@link $unitId}.
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return bool[]
	 */
	public function readInputDiscretes($unitId, $reference, $quantity)
	{
		$this->log( "readInputDiscretes: START" );
		// send FC 2
		$packet = $this->readInputDiscretesPacketBuilder($unitId, $reference, $quantity);
		$rpacket = $this->exchange($packet);
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$receivedData = $this->readInputDiscretesParser($rpacket, $quantity);
		$this->log( "readInputDiscretes: DONE" );
		// return
		return $receivedData;
	}

	/**
	 * fc2
	 *
	 * Alias to {@link readInputDiscretes} method
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return bool[]
	 */
	public function fc2($unitId, $reference, $quantity)
	{
		return $this->readInputDiscretes($unitId, $reference, $quantity);
	}

	/**
	 * readInputDiscretesPacketBuilder
	 *
	 * FC2 packet builder - read coils
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return string
	 */
	private function readInputDiscretesPacketBuilder($unitId, $reference, $quantity)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(2);              // FC 2 = 2(0x02)
		// build body - read section
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$buffer2 .= IecType::iecINT($quantity);       // quantity
		$dataLen += 5;
		// build header
		$buffer3 = '';
		$buffer3 .= $this->nextTransactionId();   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID
		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * readInputDiscretesParser
	 *
	 * FC 2 response parser, alias to FC 1 parser i.e. readCoilsParser.
	 *
	 * @param string $packet
	 * @param int    $quantity
	 * @return bool[]
	 */
	private function readInputDiscretesParser($packet, $quantity)
	{
		return $this->readCoilsParser($packet, $quantity);
	}

	/**
	 * readMultipleRegisters
	 *
	 * Modbus function FC 3(0x03) - Read Multiple Registers.
	 *
	 * This function reads {@link $quantity} of Words (2 bytes) from reference
	 * {@link $referenceRead} of a memory of a Modbus device given by
	 * {@link $unitId}.
	 *
	 *
	 * @param int $unitId    usually ID of Modbus device
	 * @param int $reference Reference in the device memory to read data (e.g. in device WAGO 750-841, memory MW0
	 *                       starts at address 12288).
	 * @param int $quantity  Amounth of the data to be read from device.
	 * @return false|array Success flag or array of received data.
	 */
	public function readMultipleRegisters($unitId, $reference, $quantity)
	{
		$this->log( "readMultipleRegisters: START" );
		// send FC 3
		$packet = $this->readMultipleRegistersPacketBuilder($unitId, $reference, $quantity);
		$rpacket = $this->exchange($packet);
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$receivedData = $this->readMultipleRegistersParser($rpacket);
		$this->log( "readMultipleRegisters: DONE" );
		// return
		return $receivedData;
	}

	/**
	 * fc3
	 *
	 * Alias to {@link readMultipleRegisters} method.
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return false|array
	 */
	public function fc3($unitId, $reference, $quantity)
	{
		return $this->readMultipleRegisters($unitId, $reference, $quantity);
	}

	/**
	 * readMultipleRegistersPacketBuilder
	 *
	 * Packet FC 3 builder - read multiple registers
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return string
	 */
	private function readMultipleRegistersPacketBuilder($unitId, $reference, $quantity)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(3);             // FC 3 = 3(0x03)
		// build body - read section
		$buffer2 .= IecType::iecINT($reference);  // refnumber = 12288
		$buffer2 .= IecType::iecINT($quantity);       // quantity
		$dataLen += 5;
		// build header
		$buffer3 = '';
		$buffer3 .= $this->nextTransactionId();   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID
		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * readMultipleRegistersParser
	 *
	 * FC 3 response parser
	 *
	 * @param string $packet
	 * @return array
	 */
	private function readMultipleRegistersParser($packet)
	{
		$data = array();
		// get data
		for ($i = 0; $i < ord($packet[8]); $i++) {
			$data[$i] = ord($packet[9 + $i]);
		}
		return $data;
	}

	/**
	 * readMultipleInputRegisters
	 *
	 * Modbus function FC 4(0x04) - Read Multiple Input Registers.
	 *
	 * This function reads {@link $quantity} of Words (2 bytes) from reference
	 * {@link $referenceRead} of a memory of a Modbus device given by
	 * {@link $unitId}.
	 *
	 *
	 * @param int $unitId    usually ID of Modbus device
	 * @param int $reference Reference in the device memory to read data.
	 * @param int $quantity  Amounth of the data to be read from device.
	 * @return false|array Success flag or array of received data.
	 */
	public function readMultipleInputRegisters($unitId, $reference, $quantity)
	{
		$this->log( "readMultipleInputRegisters: START" );
		// send FC 4
		$packet = $this->readMultipleInputRegistersPacketBuilder($unitId, $reference, $quantity);
		$rpacket = $this->exchange($packet);
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$receivedData = $this->readMultipleInputRegistersParser($rpacket);
		$this->log( "readMultipleInputRegisters: DONE" );
		// return
		return $receivedData;
	}

	/**
	 * fc4
	 *
	 * Alias to {@link readMultipleInputRegisters} method.
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return false|array
	 */
	public function fc4($unitId, $reference, $quantity)
	{
		return $this->readMultipleInputRegisters($unitId, $reference, $quantity);
	}

	/**
	 * readMultipleInputRegistersPacketBuilder
	 *
	 * Packet FC 4 builder - read multiple input registers
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return string
	 */
	private function readMultipleInputRegistersPacketBuilder($unitId, $reference, $quantity)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(4);                                                // FC 4 = 4(0x04)
		// build body - read section
		$buffer2 .= IecType::iecINT($reference);                                        // refnumber = 12288
		$buffer2 .= IecType::iecINT($quantity);                                         // quantity
		$dataLen += 5;
		// build header
		$buffer3 = '';
		$buffer3 .= $this->nextTransactionId();                                     // transaction ID
		$buffer3 .= IecType::iecINT(0);                                                 // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);                                      // lenght
		$buffer3 .= IecType::iecBYTE($unitId);                                          // unit ID
		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * readMultipleInputRegistersParser
	 *
	 * FC 4 response parser
	 *
	 * @param string $packet
	 * @return array
	 */
	private function readMultipleInputRegistersParser($packet)
	{
		$data = array();
		// get data
		for ($i = 0; $i < ord($packet[8]); $i++) {
			$data[$i] = ord($packet[9 + $i]);
		}
		return $data;
	}

	/**
	 * writeSingleCoil
	 *
	 * Modbus function FC5(0x05) - Write Single Register.
	 *
	 * This function writes {@link $data} single coil at {@link $reference} position of
	 * memory of a Modbus device given by {@link $unitId}.
	 *
	 *
	 * @param int   $unitId    usually ID of Modbus device
	 * @param int   $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at
	 *                         address 12288)
	 * @param array $data      value to be written (TRUE|FALSE).
	 * @return bool Success flag
	 */
	public function writeSingleCoil($unitId, $reference, $data)
	{
		$this->log( "writeSingleCoil: START" );
		// send FC5
		$packet = $this->writeSingleCoilPacketBuilder($unitId, $reference, $data);
		$rpacket = $this->exchange($packet);
		$this->log( $this->printPacket($rpacket) );
		$this->log( "writeSingleCoil: DONE" );
		return true;
	}

	/**
	 * fc5
	 *
	 * Alias to {@link writeSingleCoil} method
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return bool
	 */
	public function fc5($unitId, $reference, $data)
	{
		return $this->writeSingleCoil($unitId, $reference, $data);
	}

	/**
	 * writeSingleCoilPacketBuilder
	 *
	 * Packet builder FC5 - WRITE single register
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return string
	 */
	private function writeSingleCoilPacketBuilder($unitId, $reference, $data)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		foreach ($data as $key => $dataitem) {
			if ($dataitem == true) {
				$buffer1 = IecType::iecINT(0xFF00);
			} else {
				$buffer1 = IecType::iecINT(0x0000);
			};
		};
		$dataLen += 2;
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(5);             // FC5 = 5(0x05)
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$dataLen += 3;
		// build header
		$buffer3 = '';
		$buffer3 .= $this->nextTransactionId();   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID

		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * writeSingleRegister
	 *
	 * Modbus function FC6(0x06) - Write Single Register.
	 *
	 * This function writes {@link $data} single word value at {@link $reference} position of
	 * memory of a Modbus device given by {@link $unitId}.
	 *
	 *
	 * @param int   $unitId    usually ID of Modbus device
	 * @param int   $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at
	 *                         address 12288)
	 * @param array $data      Array of values to be written.
	 * @return bool Success flag
	 */
	public function writeSingleRegister($unitId, $reference, $data)
	{
		$this->log( "writeSingleRegister: START" );
		// send FC6
		$packet = $this->writeSingleRegisterPacketBuilder($unitId, $reference, $data);
		$rpacket = $this->exchange($packet);
		$this->log( $this->printPacket($rpacket) );
		$this->log( "writeSingleRegister: DONE" );
		return true;
	}

	/**
	 * fc6
	 *
	 * Alias to {@link writeSingleRegister} method
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return bool
	 */
	public function fc6($unitId, $reference, $data)
	{
		return $this->writeSingleRegister($unitId, $reference, $data);
	}

	/**
	 * writeSingleRegisterPacketBuilder
	 *
	 * Packet builder FC6 - WRITE single register
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return string
	 */
	private function writeSingleRegisterPacketBuilder($unitId, $reference, $data)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		foreach ($data as $key => $dataitem) {
			$buffer1 .= IecType::iecINT($dataitem);   // register values x
			$dataLen += 2;
			break;
		}
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(6);             // FC6 = 6(0x06)
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$dataLen += 3;
		// build header
		$buffer3 = '';
		$buffer3 .= $this->nextTransactionId();   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID

		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * writeMultipleCoils
	 *
	 * Modbus function FC15(0x0F) - Write Multiple Coils
	 *
	 * This function writes {@link $data} array at {@link $reference} position of
	 * memory of a Modbus device given by {@link $unitId}.
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return bool
	 */
	public function writeMultipleCoils($unitId, $reference, $data)
	{
		$this->log( "writeMultipleCoils: START" );
		// send FC15
		$packet = $this->writeMultipleCoilsPacketBuilder($unitId, $reference, $data);
		$rpacket = $this->exchange($packet);
		$this->log( $this->printPacket($rpacket) );
		$this->log( "writeMultipleCoils: DONE" );
		return true;
	}

	/**
	 * fc15
	 *
	 * Alias to {@link writeMultipleCoils} method
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return bool
	 */
	public function fc15($unitId, $reference, $data)
	{
		return $this->writeMultipleCoils($unitId, $reference, $data);
	}

	/**
	 * writeMultipleCoilsPacketBuilder
	 *
	 * Packet builder FC15 - Write multiple coils
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return string
	 */
	private function writeMultipleCoilsPacketBuilder($unitId, $reference, $data)
	{
		$dataLen = 0;
		// build bool stream to the WORD array
		$data_word_stream = array();
		$data_word = 0;
		$shift = 0;
		for ($i = 0; $i < count($data); $i++) {
			if ((($i % 8) == 0) && ($i > 0)) {
				$data_word_stream[] = $data_word;
				$shift = 0;
				$data_word = 0;
				$data_word |= (0x01 && $data[$i]) << $shift;
				$shift++;
			} else {
				$data_word |= (0x01 && $data[$i]) << $shift;
				$shift++;
			}
		}
		$data_word_stream[] = $data_word;
		// show binary stream to status string
		foreach ($data_word_stream as $d) {
			$this->log( sprintf("byte=b%08b\n", $d) );
		}
		// build data section
		$buffer1 = "";
		foreach ($data_word_stream as $key => $dataitem) {
			$buffer1 .= IecType::iecBYTE($dataitem);   // register values x
			$dataLen += 1;
		}
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(15);             // FC 15 = 15(0x0f)
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$buffer2 .= IecType::iecINT(count($data));      // bit count
		$buffer2 .= IecType::iecBYTE((count($data) + 7) / 8);       // byte count
		$dataLen += 6;
		// build header
		$buffer3 = '';
		$buffer3 .= $this->nextTransactionId();   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        // unit ID

		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * writeMultipleRegister
	 *
	 * Modbus function FC16(0x10) - Write Multiple Register.
	 *
	 * This function writes {@link $data} array at {@link $reference} position of
	 * memory of a Modbus device given by {@link $unitId}.
	 *
	 *
	 * @param int   $unitId    usually ID of Modbus device
	 * @param int   $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at
	 *                         address 12288)
	 * @param array $data      Array of values to be written.
	 * @param array $dataTypes Array of types of values to be written. The array should consists of string "INT",
	 *                         "DINT" and "REAL".
	 * @return bool Success flag
	 */
	public function writeMultipleRegister($unitId, $reference, $data, $dataTypes)
	{
		$this->log( "writeMultipleRegister: START" );
		// send FC16
		$packet = $this->writeMultipleRegisterPacketBuilder($unitId, $reference, $data, $dataTypes);
		$rpacket = $this->exchange($packet);
		$this->log( $this->printPacket($rpacket) );
		$this->log( "writeMultipleRegister: DONE" );
		return true;
	}

	/**
	 * fc16
	 *
	 * Alias to {@link writeMultipleRegister} method
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @param array $dataTypes
	 * @return bool
	 */
	public function fc16($unitId, $reference, $data, $dataTypes)
	{
		return $this->writeMultipleRegister($unitId, $reference, $data, $dataTypes);
	}

	/**
	 * writeMultipleRegisterPacketBuilder
	 *
	 * Packet builder FC16 - WRITE multiple register
	 *     e.g.: 4dd90000000d0010300000030603e807d00bb8
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @param array $dataTypes
	 * @return string
	 */
	private function writeMultipleRegisterPacketBuilder($unitId, $reference, $data, $dataTypes)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		foreach ($data as $key => $dataitem) {
			if ($dataTypes[$key] == "INT") {
				$buffer1 .= IecType::iecINT($dataitem);   // register values x
				$dataLen += 2;
			} elseif ($dataTypes[$key] == "DINT") {
				$buffer1 .= IecType::iecDINT($dataitem, $this->endianness);   // register values x
				$dataLen += 4;
			} elseif ($dataTypes[$key] == "REAL") {
				$buffer1 .= IecType::iecREAL($dataitem, $this->endianness);   // register values x
				$dataLen += 4;
			} else {
				$buffer1 .= IecType::iecINT($dataitem);   // register values x
				$dataLen += 2;
			}
		}
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(16);             // FC 16 = 16(0x10)
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$buffer2 .= IecType::iecINT($dataLen / 2);        // word count
		$buffer2 .= IecType::iecBYTE($dataLen);     // byte count
		$dataLen += 6;
		// build header
		$buffer3 = '';
		$buffer3 .= $this->nextTransactionId();   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID

		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * maskWriteRegister
	 *
	 * Modbus function FC22(0x16) - Mask Write Register.
	 *
	 * This function alter single bit(s) at {@link $reference} position of
	 * memory of a Modbus device given by {@link $unitId}.
	 *
	 * Result = (Current Contents AND And_Mask) OR (Or_Mask AND (NOT And_Mask))
	 *
	 * @param int $unitId    usually ID of Modbus device
	 * @param int $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at address
	 *                       12288)
	 * @param int $andMask
	 * @param int $orMask
	 * @return bool Success flag
	 */
	public function maskWriteRegister($unitId, $reference, $andMask, $orMask)
	{
		$this->log( "maskWriteRegister: START" );
		// send FC22
		$packet = $this->maskWriteRegisterPacketBuilder($unitId, $reference, $andMask, $orMask);
		$rpacket = $this->exchange($packet);
		$this->log( $this->printPacket($rpacket) );
		$this->log( "maskWriteRegister: DONE" );
		return true;
	}

	/**
	 * fc22
	 *
	 * Alias to {@link maskWriteRegister} method
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $andMask
	 * @param int $orMask
	 * @return bool
	 */
	public function fc22($unitId, $reference, $andMask, $orMask)
	{
		return $this->maskWriteRegister($unitId, $reference, $andMask, $orMask);
	}

	/**
	 * maskWriteRegisterPacketBuilder
	 *
	 * Packet builder FC22 - MASK WRITE register
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $andMask
	 * @param int $orMask
	 * @return string
	 */
	private function maskWriteRegisterPacketBuilder($unitId, $reference, $andMask, $orMask)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(22);             // FC 22 = 22(0x16)
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$buffer2 .= IecType::iecINT($andMask);        // AND mask
		$buffer2 .= IecType::iecINT($orMask);          // OR mask
		$dataLen += 7;
		// build header
		$buffer3 = '';
		$buffer3 .= $this->nextTransactionId();   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID
		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * readWriteRegisters
	 *
	 * Modbus function FC23(0x17) - Read Write Registers.
	 *
	 * This function writes {@link $data} array at reference {@link $referenceWrite}
	 * position of memory of a Modbus device given by {@link $unitId}. Simultanously,
	 * it returns {@link $quantity} of Words (2 bytes) from reference {@link $referenceRead}.
	 *
	 *
	 * @param int   $unitId         usually ID of Modbus device
	 * @param int   $referenceRead  Reference in the device memory to read data (e.g. in device WAGO 750-841, memory
	 *                              MW0 starts at address 12288).
	 * @param int   $quantity       Amounth of the data to be read from device.
	 * @param int   $referenceWrite Reference in the device memory to write data.
	 * @param array $data           Array of values to be written.
	 * @param array $dataTypes      Array of types of values to be written. The array should consists of string "INT",
	 *                              "DINT" and "REAL".
	 * @return false|array Success flag or array of data.
	 */
	public function readWriteRegisters($unitId, $referenceRead, $quantity, $referenceWrite, $data, $dataTypes)
	{
		$this->log( "readWriteRegisters: START" );
		// send FC23
		$packet = $this->readWriteRegistersPacketBuilder($unitId, $referenceRead, $quantity, $referenceWrite, $data,
			$dataTypes);
		$rpacket = $this->exchange($packet);
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$receivedData = $this->readWriteRegistersParser($rpacket);
		$this->log( "writeMultipleRegister: DONE" );
		// return
		return $receivedData;
	}

	/**
	 * fc23
	 *
	 * Alias to {@link readWriteRegisters} method.
	 *
	 * @param int   $unitId
	 * @param int   $referenceRead
	 * @param int   $quantity
	 * @param int   $referenceWrite
	 * @param array $data
	 * @param array $dataTypes
	 * @return false|array
	 */
	public function fc23($unitId, $referenceRead, $quantity, $referenceWrite, $data, $dataTypes)
	{
		return $this->readWriteRegisters($unitId, $referenceRead, $quantity, $referenceWrite, $data, $dataTypes);
	}

	/**
	 * readWriteRegistersPacketBuilder
	 *
	 * Packet FC23 builder - READ WRITE registers
	 *
	 *
	 * @param int   $unitId
	 * @param int   $referenceRead
	 * @param int   $quantity
	 * @param int   $referenceWrite
	 * @param array $data
	 * @param array $dataTypes
	 * @return string
	 */
	private function readWriteRegistersPacketBuilder(
		$unitId,
		$referenceRead,
		$quantity,
		$referenceWrite,
		$data,
		$dataTypes
	) {
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		foreach ($data as $key => $dataitem) {
			if ($dataTypes[$key] == "INT") {
				$buffer1 .= IecType::iecINT($dataitem);   // register values x
				$dataLen += 2;
			} elseif ($dataTypes[$key] == "DINT") {
				$buffer1 .= IecType::iecDINT($dataitem, $this->endianness);   // register values x
				$dataLen += 4;
			} elseif ($dataTypes[$key] == "REAL") {
				$buffer1 .= IecType::iecREAL($dataitem, $this->endianness);   // register values x
				$dataLen += 4;
			} else {
				$buffer1 .= IecType::iecINT($dataitem);   // register values x
				$dataLen += 2;
			}
		}
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(23);             // FC 23 = 23(0x17)
		// build body - read section
		$buffer2 .= IecType::iecINT($referenceRead);  // refnumber = 12288
		$buffer2 .= IecType::iecINT($quantity);       // quantity
		// build body - write section
		$buffer2 .= IecType::iecINT($referenceWrite); // refnumber = 12288
		$buffer2 .= IecType::iecINT($dataLen / 2);      // word count
		$buffer2 .= IecType::iecBYTE($dataLen);       // byte count
		$dataLen += 10;
		// build header
		$buffer3 = '';
		$buffer3 .= $this->nextTransactionId();   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID

		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * readWriteRegistersParser
	 *
	 * FC23 response parser
	 *
	 * @param string $packet
	 * @return array
	 */
	private function readWriteRegistersParser($packet)
	{
		$data = array();
		// get data
		for ($i = 0; $i < ord($packet[8]); $i++) {
			$data[$i] = ord($packet[9 + $i]);
		}
		return $data;
	}

	/**
	 * byte2hex
	 *
	 * Parse data and get it to the Hex form
	 *
	 * @param int $value
	 * @return string
	 */
	private function byte2hex($value)
	{
		$h = dechex(($value >> 4) & 0x0F);
		$l = dechex($value & 0x0F);
		return "$h$l";
	}

	/**
	 * printPacket
	 *
	 * Print a packet in the hex form
	 *
	 * @param string $packet
	 * @return string
	 */
	private function printPacket($packet)
	{
		$str = "";
		$str .= "Packet: ";
		for ($i = 0; $i < strlen($packet); $i++) {
			$str .= $this->byte2hex(ord($packet[$i]));
		}
		$str .= "\n";
		return $str;
	}

	private function log($txt)
	{
		$this->status .= $txt."\n";
		if($this->debug) echo date( DATE_RFC822 ). " - $txt\n";
	}

	public function crc16($data)
	{
		$crc = 0xFFFF;
		for ($i = 0; $i < strlen($data); $i++)
		{
			$crc ^=ord($data[$i]);
     		for ($j = 8; $j !=0; $j--)
			{
				if (($crc & 0x0001) !=0)
				{
					$crc >>= 1;
					$crc ^= 0xA001;
				}
				else
				$crc >>= 1;
			}
		}
		$highCrc=floor($crc/256);
		$lowCrc=($crc-$highCrc*256);
		return chr($lowCrc).chr($highCrc);
	}

}
