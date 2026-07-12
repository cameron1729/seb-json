<?php

/*
 * SPDX-FileCopyrightText: 2026 Cameron Ball
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [];
$status = 0;
exec('git ls-files', $files, $status);

if ($status !== 0) {
    fwrite(STDERR, "Could not list Git-tracked files.\n");
    exit(2);
}

$marker = 'TO' . 'DO';
$reference = '/\b' . $marker . '\(#[1-9]\d*\):/';
$unlinked = [];

foreach ($files as $file) {
    $contents = file_get_contents($root . '/' . $file);

    if ($contents === false) {
        fwrite(STDERR, "Could not read {$file}.\n");
        exit(2);
    }

    if (str_contains($contents, "\0")) {
        continue;
    }

    foreach (preg_split('/\R/', $contents) ?: [] as $line => $text) {
        $withoutReferences = preg_replace($reference, '', $text);

        if ($withoutReferences !== null && preg_match('/\b' . $marker . '\b/', $withoutReferences) === 1) {
            $unlinked[] = sprintf('%s:%d: %s', $file, $line + 1, trim($text));
        }
    }
}

if ($unlinked !== []) {
    fwrite(STDERR, "Every {$marker} must reference a GitHub issue as {$marker}(#123):\n");
    fwrite(STDERR, implode(PHP_EOL, $unlinked) . PHP_EOL);
    exit(1);
}

echo "All {$marker}s reference GitHub issues.\n";
