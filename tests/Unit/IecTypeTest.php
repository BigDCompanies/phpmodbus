<?php

namespace Tests\Unit;

use PHPModbus\IecType;

function iecPacketBytes(string $packet): array
{
    return array_map('ord', str_split($packet));
}

it('converts values to IEC bytes', function (int $value) {
    expect(ord(IecType::iecBYTE($value)))->toBe($value & 0xFF);
})->with([
    125,
    98,
    0,
    255,
    158,
    88,
    97,
    168,
]);

it('converts values to IEC INT packets', function (int $value, array $expectedBytes) {
    expect(iecPacketBytes(IecType::iecINT($value)))->toBe($expectedBytes);
})->with([
    [0, [0x00, 0x00]],
    [1, [0x00, 0x01]],
    [-1, [0xFF, 0xFF]],
    [32767, [0x7F, 0xFF]],
    [-32768, [0x80, 0x00]],
]);

it('converts values to IEC DINT packets', function (int $value, bool $bigEndian, array $expectedBytes) {
    expect(iecPacketBytes(IecType::iecDINT($value, $bigEndian)))->toBe($expectedBytes);
})->with([
    [0, false, [0x00, 0x00, 0x00, 0x00]],
    [1, false, [0x00, 0x01, 0x00, 0x00]],
    [-1, false, [0xFF, 0xFF, 0xFF, 0xFF]],
    [2147483647, false, [0xFF, 0xFF, 0x7F, 0xFF]],
    [-2147483648, false, [0x00, 0x00, 0x80, 0x00]],
    [0, true, [0x00, 0x00, 0x00, 0x00]],
    [1, true, [0x00, 0x00, 0x00, 0x01]],
    [-1, true, [0xFF, 0xFF, 0xFF, 0xFF]],
    [2147483647, true, [0x7F, 0xFF, 0xFF, 0xFF]],
    [-2147483648, true, [0x80, 0x00, 0x00, 0x00]],
]);

it('converts values to IEC REAL packets', function (float $value, bool $bigEndian, array $expectedBytes) {
    expect(iecPacketBytes(IecType::iecREAL($value, $bigEndian)))->toBe($expectedBytes);
})->with([
    [0.0, false, [0x00, 0x00, 0x00, 0x00]],
    [1.0, false, [0x00, 0x00, 0x3F, 0x80]],
    [-2.0, false, [0x00, 0x00, 0xC0, 0x00]],
    [25.0, false, [0x00, 0x00, 0x41, 0xC8]],
    [0.0, true, [0x00, 0x00, 0x00, 0x00]],
    [1.0, true, [0x3F, 0x80, 0x00, 0x00]],
    [-2.0, true, [0xC0, 0x00, 0x00, 0x00]],
    [25.0, true, [0x41, 0xC8, 0x00, 0x00]],
]);
