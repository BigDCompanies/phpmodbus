<?php

use PHPModbus\PhpType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Big endian - ABCD
 * Big endian (Reverse words) - CDAB
 * Little endian - DCBA
 * Little endian (Reverse words) - BADC
 */
class PhpTypeTest extends TestCase
{
    #[Test]
    #[TestWith([[0, 0, 0, 1], 1, false, false])]
    #[TestWith([[0, 1, 0, 0], 1, false, true])]
    #[TestWith([[1, 0, 0, 0], 1, true, false])]
    #[TestWith([[0, 0, 1, 0], 1, true, true])]
    #[TestWith([[0, 0, 1, 0], 256, false, false])]
    #[TestWith([[1, 0, 0, 0], 256, false, true])]
    #[TestWith([[0, 1, 0, 0], 256, true, false])]
    #[TestWith([[0, 0, 0, 1], 256, true, true])]
    #[TestWith([[0x12, 0x34, 0x56, 0x78], 305419896, false, false])]
    #[TestWith([[0x56, 0x78, 0x12, 0x34], 305419896, false, true])]
    #[TestWith([[0x78, 0x56, 0x34, 0x12], 305419896, true, false])]
    #[TestWith([[0x34, 0x12, 0x78, 0x56], 305419896, true, true])]
    public function should_parse_unsigned_integers(
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

        $this->assertEquals($expectedNumber, $output);
    }

    #[Test]
    #[TestWith([[0, 0, 0, 1], 1, false, false])]
    #[TestWith([[0xFF, 0xFF, 0xFF, 0xFF], -1, false, true])]
    #[TestWith([[0, 0, 1, 0], 256, false, false])]
    #[TestWith([[0xFF, 0xFF, 0xFF, 0x00], -256, false, false])]
    #[TestWith([[0xFF, 0x00, 0xFF, 0xFF], -256, false, true])]
    #[TestWith([[0x00, 0xFF, 0xFF, 0xFF], -256, true, false])]
    #[TestWith([[0xFF, 0xFF, 0x00, 0xFF], -256, true, true])]
    public function should_parse_signed_integers(
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

        $this->assertEquals($expectedNumber, $output);
    }

    #[Test]
    #[TestWith([[0x3C, 0xA3, 0xD7, 0x0A], 0.01, 0.01, false, false])]
    #[TestWith([[0xD7, 0x0A, 0x3C, 0xA3], 0.01, 0.01, false, true])]
    #[TestWith([[0x0A, 0xD7, 0xA3, 0x3C], 0.01, 0.01, true, false])]
    #[TestWith([[0xA3, 0x3C, 0x0A, 0xD7], 0.01, 0.01, true, true])]
    #[TestWith([[0x40, 0x48, 0xF5, 0xC3], 3.14, 0.01, false, false])]
    #[TestWith([[0xF5, 0xC3, 0x40, 0x48], 3.14, 0.01, false, true])]
    #[TestWith([[0xC3, 0xF5, 0x48, 0x40], 3.14, 0.01, true, false])]
    #[TestWith([[0x48, 0x40, 0xC3, 0xF5], 3.14, 0.01, true, true])]
    #[TestWith([[0x3F, 0x9E, 0x04, 0x0F], 1.2345679, 0.01, false, false])]
    #[TestWith([[0x04, 0x0F, 0x3F, 0x9E], 1.2345679, 0.01, false, true])]
    #[TestWith([[0x0F, 0x04, 0x9E, 0x3F], 1.2345679, 0.01, true, false])]
    #[TestWith([[0x9E, 0x3F, 0x0F, 0x04], 1.2345679, 0.01, true, true])]
    public function should_parse_floats(
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

        $abs = abs($expectedNumber - $output);
        $this->assertTrue($abs < $precision, "$abs is not within $precision from 0");
    }
}
