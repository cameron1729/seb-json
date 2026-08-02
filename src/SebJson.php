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
     * a JSON-ish byte format used when generating Safe Exam Browser Config Keys.
     *
     * This method only encodes values. Callers remain responsible for any
     * Config Key canonicalisation, including key ordering and filtering.
     * PHP objects are intentionally rejected: SEB-JSON serialises property list
     * dictionaries, arrays and scalar values, not arbitrary application objects.
     * Recursive arrays are rejected because SEB-JSON cannot represent references.
     * PHP strings, including dictionary keys, must contain valid UTF-8.
     * Integers are limited to the signed 32-bit range shared by both platforms.
     * Null is rejected because the platform value serialisers do not agree.
     * Finite floats use the invariant G15 representation shared by the
     * supported Windows and macOS value serialisers.
     *
     * @param mixed $value The PHP value to encode.
     * @return string The SEB-JSON byte representation of the value.
     * @throws InvalidArgumentException If the value cannot be represented as SEB-JSON.
     *
     * phpcs:disable Generic.Files.LineLength.TooLong -- Stable upstream source URLs.
     * @see https://github.com/SafeExamBrowser/seb-win-refactoring/blob/v3.10.2/SafeExamBrowser.Configuration/ConfigurationData/Json.cs Windows value serialiser.
     * @see https://github.com/SafeExamBrowser/seb-mac/blob/3.7/Classes/Cryptography/SEBCryptor.m macOS value serialiser.
     * phpcs:enable Generic.Files.LineLength.TooLong
     */
    public static function encode(mixed $value): string
    {
        return match (self::isAcyclic($value)) {
            true => self::encodeValue($value),
            false => throw new InvalidArgumentException('Recursive arrays cannot be encoded.'),
        };
    }

    /**
     * Encode an acyclic PHP value as SEB-JSON.
     *
     * @param mixed $value The acyclic PHP value to encode.
     * @return string The SEB-JSON byte representation of the value.
     * @throws InvalidArgumentException If the value cannot be represented as SEB-JSON.
     */
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

        return match (true) {
            is_array($value) => array_is_list($value) ? $list($value) : $map($value),
            is_string($value) && $invalidUtf8($value) => throw new InvalidArgumentException($invalidUtf8Message),
            is_string($value) => '"' . $value . '"',
            is_int($value) && $outOfRangeInteger($value) => throw new InvalidArgumentException($integerRangeMessage),
            is_int($value) => (string)$value,
            is_float($value) && !is_finite($value) => throw new InvalidArgumentException('Cannot encode NAN or INF.'),
            is_float($value) => self::encodeFloat($value),
            is_bool($value) => $value ? 'true' : 'false',
            default => throw new InvalidArgumentException(sprintf('Cannot encode %s.', get_debug_type($value))),
        };
    }

    /**
     * Encode a finite float using the invariant G15 format shared by the supported SEB releases.
     *
     * Windows uses .NET Framework's Double.ToString with invariant formatting. macOS 3.7 deliberately matches it
     * with %.15g, an uppercase exponent and zero normalisation. PHP's locale-independent %.15H has the same
     * precision and fixed/scientific threshold, but retains a redundant .0 in scientific notation and does not pad
     * a single-digit exponent. Normalise those differences to produce the same bytes as both reference encoders.
     *
     * This is SEB's interim G15 behaviour, not the RFC 8785 number format proposed for SEB 4.0.
     *
     * TODO(#1): Revisit float encoding when upstream SEB adopts a normative number format.
     *
     * @param float $number The finite float to encode.
     * @return string The G15 representation of the float.
     *
     * @see https://github.com/SafeExamBrowser/seb-win-refactoring/issues/1495 Upstream float serialisation issue.
     * @see https://learn.microsoft.com/en-us/dotnet/api/system.double.tostring?view=netframework-4.8.1
     */
    private static function encodeFloat(float $number): string
    {
        $formatted = $number == 0.0 ? '0' : sprintf('%.15H', $number);
        $formatted = str_replace('.0E', 'E', $formatted);
        return preg_replace('/E([+-])(\d)$/', 'E${1}0${2}', $formatted) ?? $formatted;
    }

    /**
     * Check whether a value is free of recursive array references.
     *
     * @param mixed $value The PHP value to inspect.
     * @param string ...$ancestors Identifiers for array references already present in the current ancestry.
     * @return bool Whether the value is free of recursive array references.
     */
    private static function isAcyclic(mixed $value, string ...$ancestors): bool
    {
        if (!is_array($value)) {
            return true;
        }

        // TODO(#2): Use array_all once the generated Moodle support baseline reaches PHP 8.4.
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
