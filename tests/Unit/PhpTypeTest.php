<?php

namespace Tests\Unit;

use PHPModbus\PhpType;

/**
 * Big endian - ABCD
 * Big endian (Reverse words) - CDAB
 * Little endian - DCBA
 * Little endian (Reverse words) - BADC
 */
it('parses unsigned integers', function (
    array $inputBytes,
    int $expectedNumber,
    bool $reverseBytes,
    bool $reversedWords
) {
    $output = PhpType::bytes2unsignedInt(
        values: $inputBytes,
        bigEndian: $reverseBytes,
        reverseWords: $reversedWords
    );

    expect($output)->toBe($expectedNumber);
})->with([
    [[0, 0, 0, 1], 1, false, false],
    [[0, 1, 0, 0], 1, false, true],
    [[1, 0, 0, 0], 1, true, false],
    [[0, 0, 1, 0], 1, true, true],
    [[0, 0, 1, 0], 256, false, false],
    [[1, 0, 0, 0], 256, false, true],
    [[0, 1, 0, 0], 256, true, false],
    [[0, 0, 0, 1], 256, true, true],
    [[0x12, 0x34, 0x56, 0x78], 305419896, false, false],
    [[0x56, 0x78, 0x12, 0x34], 305419896, false, true],
    [[0x78, 0x56, 0x34, 0x12], 305419896, true, false],
    [[0x34, 0x12, 0x78, 0x56], 305419896, true, true],
]);

it('parses signed integers', function (
    array $inputBytes,
    int $expectedNumber,
    bool $reverseBytes,
    bool $reversedWords
) {
    $output = PhpType::bytes2signedInt(
        values: $inputBytes,
        bigEndian: $reverseBytes,
        reverseWords: $reversedWords
    );

    expect($output)->toBe($expectedNumber);
})->with([
    [[0, 0, 0, 1], 1, false, false],
    [[0xFF, 0xFF, 0xFF, 0xFF], -1, false, true],
    [[0, 0, 1, 0], 256, false, false],
    [[0xFF, 0xFF, 0xFF, 0x00], -256, false, false],
    [[0xFF, 0x00, 0xFF, 0xFF], -256, false, true],
    [[0x00, 0xFF, 0xFF, 0xFF], -256, true, false],
    [[0xFF, 0xFF, 0x00, 0xFF], -256, true, true],
]);

it('parses floats', function (
    array $inputBytes,
    float $expectedNumber,
    float $precision,
    bool $reverseBytes,
    bool $reversedWords
) {
    $output = PhpType::bytes2float(
        values: $inputBytes,
        bigEndian: $reverseBytes,
        reverseWords: $reversedWords
    );

    expect($output)->toBeGreaterThanOrEqual($expectedNumber - $precision)
        ->and($output)->toBeLessThanOrEqual($expectedNumber + $precision);
})->with([
    [[0x3C, 0xA3, 0xD7, 0x0A], 0.01, 0.01, false, false],
    [[0xD7, 0x0A, 0x3C, 0xA3], 0.01, 0.01, false, true],
    [[0x0A, 0xD7, 0xA3, 0x3C], 0.01, 0.01, true, false],
    [[0xA3, 0x3C, 0x0A, 0xD7], 0.01, 0.01, true, true],
    [[0x40, 0x48, 0xF5, 0xC3], 3.14, 0.01, false, false],
    [[0xF5, 0xC3, 0x40, 0x48], 3.14, 0.01, false, true],
    [[0xC3, 0xF5, 0x48, 0x40], 3.14, 0.01, true, false],
    [[0x48, 0x40, 0xC3, 0xF5], 3.14, 0.01, true, true],
    [[0x3F, 0x9E, 0x04, 0x0F], 1.2345679, 0.0001, false, false],
    [[0x04, 0x0F, 0x3F, 0x9E], 1.2345679, 0.0001, false, true],
    [[0x0F, 0x04, 0x9E, 0x3F], 1.2345679, 0.0001, true, false],
    [[0x9E, 0x3F, 0x0F, 0x04], 1.2345679, 0.0001, true, true],
]);

it('parses two-byte integers', function (array $inputBytes, int $expectedSigned, int $expectedUnsigned) {
    expect(PhpType::bytes2signedInt($inputBytes))->toBe($expectedSigned)
        ->and(PhpType::bytes2unsignedInt($inputBytes))->toBe($expectedUnsigned);
})->with([
    [[0x00, 0x00], 0, 0],
    [[0x00, 0x01], 1, 1],
    [[0xFF, 0xFF], -1, 65535],
    [[0x9E, 0x58], -25000, 40536],
    [[0x61, 0xA8], 25000, 25000],
]);

it('parses strings until the first null byte', function (bool $bigEndian, string $expected) {
    $data = [
        0x48,
        0x65,
        0x6C,
        0x6C,
        0x6F,
        0x20,
        0x77,
        0x6F,
        0x72,
        0x6C,
        0x64,
        0x21,
        0x00,
        0x61,
        0x61,
        0x61,
    ];

    expect(PhpType::bytes2string($data, $bigEndian))->toBe($expected);
})->with([
    [false, 'eHll oowlr!da'],
    [true, 'Hello world!'],
]);

it('rejects non-numeric integer bytes', function () {
    PhpType::bytes2unsignedInt([100, 'e', 0, 0]);
})->throws(\Exception::class, 'Data are not numeric');

it('rejects invalid integer byte counts', function (array $inputBytes) {
    PhpType::bytes2unsignedInt($inputBytes);
})->with([
    [[100]],
    [[100, 2, 0]],
    [[100, 2, 0, 0, 100]],
])->throws(\Exception::class, 'The input data should be an array of 2 or 4 bytes.');
