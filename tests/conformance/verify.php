<?php

/*
 * SPDX-FileCopyrightText: 2026 Cameron Ball
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use cameron1729\SebJson\SebJson;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

if (count($arguments) !== 4) {
    fwrite(STDERR, "Usage: php tests/conformance/verify.php <vectors> <windows-results> <macos-results>\n");
    exit(2);
}

$vectorReader = new class {
    /**
     * @return array<string, mixed>
     */
    public function read(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException("Could not read vectors from {$file}.");
        }

        $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $document = $this->object($document, 'vector document');
        $items = $document['vectors'] ?? null;

        if (!is_array($items) || !array_is_list($items)) {
            throw new RuntimeException('Vector document has no vector list.');
        }

        $vectors = [];

        foreach ($items as $item) {
            $vector = $this->object($item, 'vector');
            $name = $this->string($vector['name'] ?? null, 'vector name');

            if ($name === '' || array_key_exists($name, $vectors)) {
                throw new RuntimeException("Vector name is empty or duplicated: {$name}");
            }

            $vectors[$name] = $this->value($this->object($vector['value'] ?? null, "value for {$name}"));
        }

        return $vectors;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function value(array $node): mixed
    {
        $type = $this->string($node['type'] ?? null, 'value type');

        return match ($type) {
            'string' => $this->base64($node['base64'] ?? null, 'string'),
            'integer' => $this->integer($node['decimal'] ?? null),
            'float' => $this->float($node['hex'] ?? null),
            'boolean' => $this->boolean($node['boolean'] ?? null),
            'null' => null,
            'list' => $this->list($node['items'] ?? null),
            'map' => $this->map($node['entries'] ?? null),
            default => throw new RuntimeException("Unknown vector type: {$type}"),
        };
    }

    private function integer(mixed $value): int
    {
        $value = $this->string($value, 'integer');
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if ($integer === false || (string)$integer !== $value) {
            throw new RuntimeException("Invalid integer vector: {$value}");
        }

        return $integer;
    }

    private function float(mixed $value): float
    {
        $hex = $this->string($value, 'IEEE-754 value');

        if (preg_match('/\A[0-9a-f]{16}\z/D', $hex) !== 1) {
            throw new RuntimeException("Invalid IEEE-754 vector: {$hex}");
        }

        $bytes = hex2bin($hex);
        $unpacked = $bytes === false ? false : unpack('Evalue', $bytes);
        $number = $unpacked['value'] ?? null;

        if (!is_float($number)) {
            throw new RuntimeException("Could not decode IEEE-754 vector: {$hex}");
        }

        return $number;
    }

    private function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new RuntimeException('Boolean vector has no boolean value.');
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('List vector has no item list.');
        }

        return array_map(
            fn(mixed $item): mixed => $this->value($this->object($item, 'list item')),
            $value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function map(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Map vector has no entry list.');
        }

        $result = [];

        foreach ($value as $item) {
            $entry = $this->object($item, 'map entry');
            $key = $this->base64($entry['key'] ?? null, 'map key');

            if (array_key_exists($key, $result)) {
                throw new RuntimeException("Map vector contains duplicate key: {$key}");
            }

            $result[$key] = $this->value($this->object($entry['value'] ?? null, "value for map key {$key}"));
        }

        return $result;
    }

    private function base64(mixed $value, string $field): string
    {
        $value = $this->string($value, $field);
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw new RuntimeException("Invalid Base64 {$field} vector.");
        }

        return $decoded;
    }

    private function string(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new RuntimeException("Vector field {$field} is not a string.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException("Vector field {$field} is not an object.");
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException("Vector field {$field} has a non-string key.");
            }

            $result[$key] = $item;
        }

        return $result;
    }
};

/**
 * @return array<string, array{status: string, value: string}>
 */
$readReferenceResults = function (string $file): array {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        throw new RuntimeException("Could not read reference results from {$file}.");
    }

    $results = [];

    foreach ($lines as $line) {
        $parts = explode("\t", $line, 3);

        if (count($parts) !== 3) {
            throw new RuntimeException("Invalid reference result: {$line}");
        }

        [$name, $status, $base64] = $parts;
        $value = base64_decode($base64, true);

        if (
            $name === '' ||
            !in_array($status, ['ok', 'unsupported'], true) ||
            array_key_exists($name, $results) ||
            $value === false
        ) {
            throw new RuntimeException("Invalid or duplicate reference result: {$name}");
        }

        $results[$name] = [
            'status' => $status,
            'value' => $value,
        ];
    }

    return $results;
};

$vectors = $vectorReader->read($arguments[1]);
$windows = $readReferenceResults($arguments[2]);
$macos = $readReferenceResults($arguments[3]);
$errors = [];

if (array_keys($windows) !== array_keys($vectors)) {
    $errors[] = 'Windows results do not contain exactly the configured vectors.';
}

if (array_keys($macos) !== array_keys($vectors)) {
    $errors[] = 'macOS results do not contain exactly the configured vectors.';
}

foreach ($vectors as $name => $value) {
    if (!isset($windows[$name], $macos[$name])) {
        continue;
    }

    $windowsResult = $windows[$name];
    $macosResult = $macos[$name];
    $upstreamAgrees = $windowsResult['status'] === 'ok' &&
        $macosResult['status'] === 'ok' &&
        $windowsResult['value'] === $macosResult['value'];

    if (!$upstreamAgrees) {
        try {
            $actual = SebJson::encode($value);
            $errors[] = sprintf(
                '%s: upstream differs (Windows %s %s, macOS %s %s), but PHP encoded %s.',
                $name,
                $windowsResult['status'],
                var_export($windowsResult['value'], true),
                $macosResult['status'],
                var_export($macosResult['value'], true),
                var_export($actual, true),
            );
        } catch (InvalidArgumentException) {
            printf("PASS %-36s rejected upstream disagreement\n", $name);
        }

        continue;
    }

    try {
        $actual = SebJson::encode($value);
    } catch (InvalidArgumentException $exception) {
        $errors[] = sprintf(
            '%s: upstream agrees on %s, but PHP rejected it: %s',
            $name,
            var_export($windowsResult['value'], true),
            $exception->getMessage(),
        );
        continue;
    }

    if ($actual !== $windowsResult['value']) {
        $errors[] = sprintf(
            '%s: expected %s from both upstream implementations, got %s.',
            $name,
            var_export($windowsResult['value'], true),
            var_export($actual, true),
        );
        continue;
    }

    printf("PASS %-36s %s\n", $name, base64_encode($actual));
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "FAIL {$error}\n");
    }

    exit(1);
}

printf("SEB conformance passed for %d vectors.\n", count($vectors));
