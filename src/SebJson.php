<?php

/*
 * SPDX-FileCopyrightText: 2026 Cameron Ball
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace cameron1729\SebJson;

use InvalidArgumentException;
use ReflectionReference;

final class SebJson
{
    /**
     * Encode a PHP value as Safe Exam Browser SEB-JSON.
     *
     * This encoder intentionally does not use JSON string escaping. SEB-JSON is
     * a JSON-ish byte format used by Safe Exam Browser config-key generation.
     *
     * This method only encodes values. Callers remain responsible for any
     * config-key canonicalisation, including key ordering and filtering.
     * PHP objects are intentionally rejected: SEB-JSON serialises property-list
     * dictionaries, arrays and scalar values, not arbitrary application objects.
     * Recursive arrays are rejected because SEB-JSON cannot represent references.
     * PHP strings, including dictionary keys, must contain valid UTF-8.
     * Integers are limited to the signed 32-bit range shared by both platforms.
     * Null is rejected because the platform value serialisers do not agree.
     * Floats are accepted only when the Windows and macOS value serialisers
     * produce the same representation.
     *
     * phpcs:disable Generic.Files.LineLength.TooLong -- Stable upstream source URLs.
     * @see https://github.com/SafeExamBrowser/seb-win-refactoring/blob/v3.10.1/SafeExamBrowser.Configuration/ConfigurationData/Json.cs Windows value serialiser.
     * @see https://github.com/SafeExamBrowser/seb-mac/blob/3.6.1/Classes/Cryptography/SEBCryptor.m macOS value serialiser.
     * phpcs:enable Generic.Files.LineLength.TooLong
     */
    public static function encode(mixed $value): string
    {
        return match (self::isAcyclic($value)) {
            true => self::encodeValue($value),
            false => throw new InvalidArgumentException('Recursive arrays cannot be encoded.'),
        };
    }

    private static function encodeValue(mixed $value): string
    {
        $entry = fn(int|string $k, mixed $v): string => self::encodeValue((string)$k) . ':' . self::encodeValue($v);
        $entries = fn(array $items): array => array_map($entry, array_keys($items), $items);
        $list = fn(array $items): string => '[' . implode(',', array_map(self::encodeValue(...), $items)) . ']';
        $map = fn(array $items): string => '{' . implode(',', $entries($items)) . '}';
        $invalidUtf8 = fn(string $string): bool => preg_match('//u', $string) !== 1;
        $invalidUtf8Message = 'String is not valid UTF-8.';
        $outOfRangeInteger = fn(int $number): bool => $number < -2_147_483_648 || $number > 2_147_483_647;
        $integerRangeMessage = 'Integer is outside the cross-platform SEB-JSON range.';

        /**
         * Windows formats doubles with .NET Framework's Double.ToString (invariant G15), emulated by %.15H,
         * while macOS formats an NSNumber with its Foundation description, emulated by %.16h.
         *
         * A float is safe to encode if and only if these emulations produce identical SEB-JSON bytes. Negative zero
         * is rejected separately because PHP's formatters do not expose the upstream platform disagreement for it.
         *
         * Rejecting these mismatches is a compatibility workaround for the ambiguous SEB-JSON specification.
         * Once the specification and supported SEB implementations converge, this guard can be removed or
         * replaced with the specified encoding.
         *
         * TODO: Remove all this stuff once upstream SEB-JSON resolves the ambiguity.
         *
         * @see https://github.com/SafeExamBrowser/seb-win-refactoring/issues/1495 Upstream float serialisation issue.
         * @see https://learn.microsoft.com/en-us/dotnet/api/system.double.tostring?view=netframework-4.8.1
         * @see https://developer.apple.com/documentation/foundation/nsnumber/description(withlocale:)
         */
        $float = fn(float $number): string => $number == 0.0 ? '0' : sprintf('%.15H', $number);
        $negativeZero = fn(float $number): bool => $number == 0.0 && fdiv(1.0, $number) < 0;
        $platformsDiffer = fn(float $number): bool => sprintf('%.15H', $number) !== sprintf('%.16h', $number);
        $ambiguousFloat = fn(float $number): bool => $negativeZero($number) || $platformsDiffer($number);
        $ambiguousFloatMessage = 'Float does not have an unambiguous cross-platform SEB-JSON representation.';

        return match (true) {
            is_array($value) => array_is_list($value) ? $list($value) : $map($value),
            is_string($value) && $invalidUtf8($value) => throw new InvalidArgumentException($invalidUtf8Message),
            is_string($value) => '"' . $value . '"',
            is_int($value) && $outOfRangeInteger($value) => throw new InvalidArgumentException($integerRangeMessage),
            is_int($value) => (string)$value,
            is_float($value) && !is_finite($value) => throw new InvalidArgumentException('Cannot encode NAN or INF.'),
            is_float($value) && $ambiguousFloat($value) => throw new InvalidArgumentException($ambiguousFloatMessage),
            is_float($value) => $float($value),
            is_bool($value) => $value ? 'true' : 'false',
            default => throw new InvalidArgumentException(sprintf('Cannot encode %s.', get_debug_type($value))),
        };
    }

    /**
     * Check whether a value is free of recursive array references.
     *
     * Returns true when no array reference is revisited in its own ancestry.
     */
    private static function isAcyclic(mixed $value, string ...$ancestors): bool
    {
        if (!is_array($value)) {
            return true;
        }

        // TODO: Use array_all once the generated Moodle support baseline reaches PHP 8.4.
        $and = fn(array $xs, callable $f): callable => fn(bool $all, int|string $k): bool => $all && $f($xs[$k], $k);
        $all = fn(array $items, callable $test): bool => array_reduce(array_keys($items), $and($items, $test), true);

        $extend = fn(?string $id): array => $id === null ? $ancestors : [$id, ...$ancestors];
        $fresh = fn(?string $id): bool => $id === null || !in_array($id, $ancestors, true);
        $descend = fn(array $item, ?string $id): bool => $fresh($id) && self::isAcyclic($item, ...$extend($id));
        $reference = fn(int|string $key): ?string => ReflectionReference::fromArrayElement($value, $key)?->getId();
        $child = fn(mixed $item, int|string $key): bool => !is_array($item) || $descend($item, $reference($key));

        return $all($value, $child);
    }
}
