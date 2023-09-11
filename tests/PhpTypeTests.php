<?php

use PHPModbus\PhpType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class PhpTypeTests extends TestCase
{
    #[Test]
    #[TestWith([[0, 0, 0, 1], 1, false, false])] // Big endian
    #[TestWith([[0, 1, 0, 0], 1, false, true])] // Big endian (Reverse words)
    #[TestWith([[1, 0, 0, 0], 1, true, false])] // Little endian
    #[TestWith([[0, 0, 1, 0], 1, true, true])] // Little endian (Reverse words)

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
}
