<?php

namespace Tests\Feature\Stage15;

use App\Services\Migration\ExactDecimalConverter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExactDecimalConverterTest extends TestCase
{
    #[DataProvider('exactValues')]
    public function test_converts_decimal_strings_to_exact_integer_units(string|int $value, int $scale, int $expected): void
    {
        $converter = new ExactDecimalConverter;

        $this->assertSame($expected, $converter->scaledInteger($value, $scale, true));
    }

    /** @return array<string, array{string|int,int,int}> */
    public static function exactValues(): array
    {
        return [
            'money' => ['123.45', 2, 12345], 'money padded' => ['123.4', 2, 12340], 'zero' => ['0.00', 2, 0],
            'quantity' => ['12.345', 3, 12345], 'basis points' => ['18.00', 2, 1800], 'large exact' => ['90071992547409.91', 2, 9007199254740991],
            'negative allowed' => ['-1.25', 2, -125], 'integer input' => [12, 2, 1200],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_floats_unsupported_precision_invalid_format_negative_and_overflow(mixed $value, int $scale, bool $allowNegative): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ExactDecimalConverter)->scaledInteger($value, $scale, $allowNegative);
    }

    /** @return array<string, array{mixed,int,bool}> */
    public static function invalidValues(): array
    {
        return [
            'float' => [123.45, 2, false], 'unsupported precision' => ['1.001', 2, false], 'negative' => ['-1.00', 2, false],
            'scientific notation' => ['1e3', 2, false], 'comma' => ['1,000.00', 2, false], 'overflow' => ['92233720368547758.08', 2, false],
        ];
    }
}
