<?php

/*
 * SPDX-FileCopyrightText: 2026 Cameron Ball
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace cameron1729\SebJson\Tests;

use cameron1729\SebJson\SebJson;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SebJsonTest extends TestCase
{
    public function testEncodesNestedAssociativeArrays(): void
    {
        $this->assertSame(
            '{"asdf":{"argeg":"swag"},"myname":"chef","day":"aaaaa","go":{"even":{"further":"beyond","the":"swag"}}}',
            SebJson::encode([
                'asdf' => [
                    'argeg' => 'swag',
                ],
                'myname' => 'chef',
                'day' => 'aaaaa',
                'go' => [
                    'even' => [
                        'further' => 'beyond',
                        'the' => 'swag',
                    ],
                ],
            ]),
        );
    }

    public function testEncodesBackslashesWithoutJsonEscaping(): void
    {
        $this->assertSame(
            '{"browserMessagingSocket":"ws:\localhost:8706"}',
            SebJson::encode(['browserMessagingSocket' => 'ws:\localhost:8706']),
        );
    }

    #[DataProvider('rawStringProvider')]
    public function testEncodesStringsWithoutJsonEscaping(string $value): void
    {
        $this->assertSame(
            '{"string":"' . $value . '"}',
            SebJson::encode(['string' => $value]),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rawStringProvider(): array
    {
        return [
            'double quote' => ['a"b'],
            'solidus' => ['http://chef.dvd'],
            '1 backslash' => ['ws:' . str_repeat('\\', 1) . 'localhost'],
            '2 backslashes' => ['ws:' . str_repeat('\\', 2) . 'localhost'],
            '3 backslashes' => ['ws:' . str_repeat('\\', 3) . 'localhost'],
            '4 backslashes' => ['ws:' . str_repeat('\\', 4) . 'localhost'],
            '5 backslashes' => ['ws:' . str_repeat('\\', 5) . 'localhost'],
            'backspace' => ["a\x08b"],
            'form feed' => ["a\x0Cb"],
            'newline' => ["a\nb"],
            'carriage return' => ["a\rb"],
            'tab' => ["a\tb"],
            'non-ASCII' => ["Stra\u{00DF}e \u{1F600}"],
        ];
    }

    public function testEncodesControlCharactersWithoutJsonEscaping(): void
    {
        $this->assertSame(
            "{\"string\":\"line1\nline2\tend\"}",
            SebJson::encode(['string' => "line1\nline2\tend"]),
        );
    }

    public function testRejectsInvalidUtf8StringValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('String is not valid UTF-8.');

        SebJson::encode("\xC3\x28");
    }

    public function testRejectsInvalidUtf8StringKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('String is not valid UTF-8.');

        SebJson::encode(["\x80" => 'value']);
    }

    public function testEncodesAssociativeArrayKeysWithoutJsonEscaping(): void
    {
        $this->assertSame(
            "{\"a\"b\":\"quote\",\"line\nbreak\":\"newline\"}",
            SebJson::encode([
                'a"b' => 'quote',
                "line\nbreak" => 'newline',
            ]),
        );
    }

    public function testEncodesIntegerKeysAsMapKeyStrings(): void
    {
        $this->assertSame(
            '{"2":"two","0":"zero"}',
            SebJson::encode([
                2 => 'two',
                0 => 'zero',
            ]),
        );
    }

    public function testEncodesListsAsArrays(): void
    {
        $this->assertSame(
            '{"URLFilterRules":[{"active":true,"expression":"safeexambrowser.org/*"}]}',
            SebJson::encode([
                'URLFilterRules' => [
                    [
                        'active' => true,
                        'expression' => 'safeexambrowser.org/*',
                    ],
                ],
            ]),
        );
    }

    public function testRejectsSelfReferencingMap(): void
    {
        $value = [];
        $value['self'] =& $value;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recursive arrays cannot be encoded.');

        SebJson::encode($value);
    }

    public function testRejectsSelfReferencingList(): void
    {
        $value = [];
        $value[] =& $value;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recursive arrays cannot be encoded.');

        SebJson::encode($value);
    }

    public function testRejectsMutuallyRecursiveArrays(): void
    {
        $first = [];
        $second = [];
        $first['second'] =& $second;
        $second['first'] =& $first;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recursive arrays cannot be encoded.');

        SebJson::encode($first);
    }

    public function testEncodesSharedArrayReferencesByValue(): void
    {
        $shared = ['value' => 'shared'];

        $this->assertSame(
            '{"first":{"value":"shared"},"second":{"value":"shared"}}',
            SebJson::encode([
                'first' => &$shared,
                'second' => &$shared,
            ]),
        );
    }

    public function testEncodesScalars(): void
    {
        $this->assertSame(
            '{"true":true,"false":false,"int":42,"float":0.1}',
            SebJson::encode([
                'true' => true,
                'false' => false,
                'int' => 42,
                'float' => 0.1,
            ]),
        );
    }

    #[DataProvider('sharedIntegerProvider')]
    public function testEncodesIntegersInSharedRange(int $value, string $expected): void
    {
        $this->assertSame($expected, SebJson::encode($value));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function sharedIntegerProvider(): array
    {
        return [
            'minimum' => [-2_147_483_648, '-2147483648'],
            'negative' => [-42, '-42'],
            'zero' => [0, '0'],
            'positive' => [42, '42'],
            'maximum' => [2_147_483_647, '2147483647'],
        ];
    }

    #[DataProvider('outOfRangeIntegerProvider')]
    public function testRejectsIntegersOutsideSharedRange(int $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cross-platform SEB-JSON range');

        SebJson::encode($value);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function outOfRangeIntegerProvider(): array
    {
        return [
            'below minimum' => [-2_147_483_649],
            'above maximum' => [2_147_483_648],
        ];
    }

    public function testRejectsNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot encode null.');

        SebJson::encode(null);
    }

    public function testPreservesAssociativeArrayKeyOrderRecursively(): void
    {
        $this->assertSame(
            '{"z":1,"a":{"c":3,"b":2},"list":[{"z":false,"a":true}]}',
            SebJson::encode([
                'z' => 1,
                'a' => [
                    'c' => 3,
                    'b' => 2,
                ],
                'list' => [
                    [
                        'z' => false,
                        'a' => true,
                    ],
                ],
            ]),
        );
    }

    public function testEncodesOriginatorVersionLikeAnyOtherKey(): void
    {
        $this->assertSame(
            '{"originatorVersion":"SEB_3.6.1","value":2,"nested":{"OriginatorVersion":"SEB_3.10.1","value":1}}',
            SebJson::encode([
                'originatorVersion' => 'SEB_3.6.1',
                'value' => 2,
                'nested' => [
                    'OriginatorVersion' => 'SEB_3.10.1',
                    'value' => 1,
                ],
            ]),
        );
    }

    public function testEncodesEmptyLists(): void
    {
        $this->assertSame(
            '{"items":[]}',
            SebJson::encode(['items' => []]),
        );
    }

    public function testRejectsObjects(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SebJson::encode((object) ['value' => 'test']);
    }

    public function testRejectsJsonSerializableObjects(): void
    {
        $value = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['value' => 'test'];
            }
        };

        $this->expectException(InvalidArgumentException::class);

        SebJson::encode($value);
    }

    public function testRejectsResources(): void
    {
        $resource = fopen('php://memory', 'rb');
        $this->assertIsResource($resource);

        try {
            $this->expectException(InvalidArgumentException::class);

            SebJson::encode($resource);
        } finally {
            fclose($resource);
        }
    }

    public function testEncodesFloatWithoutPreservingZeroFraction(): void
    {
        $this->assertSame('{"one":1,"rounded":0.1}', SebJson::encode([
            'one' => 1.0,
            'rounded' => 0.10000000000000001,
        ]));
    }

    #[DataProvider('unambiguousFloatProvider')]
    public function testEncodesUnambiguousCrossPlatformFloats(float $value, string $expected): void
    {
        $this->assertSame($expected, SebJson::encode($value));
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function unambiguousFloatProvider(): array
    {
        return [
            'zero' => [0.0, '0'],
            'one tenth' => [0.1, '0.1'],
            'one fifth' => [0.2, '0.2'],
            'one' => [1.0, '1'],
            'quarter' => [0.25, '0.25'],
            'negative one tenth' => [-0.1, '-0.1'],
            'simple decimal' => [0.12345, '0.12345'],
            'fixed lower boundary' => [1.0E-4, '0.0001'],
            'large fixed value' => [1.0E+14, '100000000000000'],
        ];
    }

    #[DataProvider('ambiguousFloatProvider')]
    public function testRejectsAmbiguousCrossPlatformFloats(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unambiguous cross-platform');

        SebJson::encode($value);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function ambiguousFloatProvider(): array
    {
        return [
            'negative zero' => [-0.0],
            'high precision' => [1.2345678901234567],
            'small scientific notation' => [1.0E-5],
            'smaller scientific notation' => [1.0E-6],
            'Windows scientific boundary' => [1.0E+15],
            'large scientific boundary' => [1.0E+16],
            'large scientific notation' => [1.0E+20],
            'minimum normal' => [PHP_FLOAT_MIN],
            'minimum subnormal' => [5.0E-324],
            'maximum finite' => [PHP_FLOAT_MAX],
        ];
    }

    public function testFloatEncodingDoesNotDependOnPhpPrecision(): void
    {
        $precision = ini_get('precision');

        try {
            foreach ([2, 14, 17, -1] as $value) {
                ini_set('precision', (string)$value);
                $this->assertSame('0.12345', SebJson::encode(0.12345));
            }
        } finally {
            ini_set('precision', $precision);
        }
    }

    #[DataProvider('nonFiniteFloatProvider')]
    public function testRejectsNonFiniteFloats(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        SebJson::encode($value);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function nonFiniteFloatProvider(): array
    {
        return [
            'NAN' => [NAN],
            'positive infinity' => [INF],
            'negative infinity' => [-INF],
        ];
    }
}
