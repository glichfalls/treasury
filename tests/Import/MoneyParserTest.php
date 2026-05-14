<?php

namespace App\Tests\Import;

use App\Import\MoneyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyParserTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'plain integer' => ['100', '10000'],
            'two decimals' => ['12.34', '1234'],
            'negative' => ['-12.34', '-1234'],
            'no leading int' => ['0.50', '50'],
            'rounding down' => ['1.234', '123'],
            'rounding up' => ['1.235', '124'],
            'rounding up many digits' => ['-768.792214', '-76879'],
            'thousands apostrophe (CH)' => ["4'959.70", '495970'],
            'thousands space' => ['4 959.70', '495970'],
            'pure zero' => ['0', '0'],
            'whitespace padding' => [' 1.00 ', '100'],
            'no fractional part' => ['42', '4200'],
        ];
    }

    #[DataProvider('cases')]
    public function testToMinor(string $input, string $expected): void
    {
        $this->assertSame($expected, MoneyParser::toMinor($input));
    }

    public function testCustomExponent(): void
    {
        // KWD has 3 decimal places.
        $this->assertSame('12345', MoneyParser::toMinor('12.345', 3));
    }

    public function testRejectsInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoneyParser::toMinor('abc');
    }

    public function testEmptyStringYieldsZero(): void
    {
        $this->assertSame('0', MoneyParser::toMinor(''));
    }
}
