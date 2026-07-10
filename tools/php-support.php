<?php

/*
 * SPDX-FileCopyrightText: 2026 Cameron Ball
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use cameron1729\SebJson\Tools\PhpSupport;

require __DIR__ . '/PhpSupport.php';

$root = dirname(__DIR__);
$manifestPath = $root . '/.github/php-support.json';
$composerPath = $root . '/composer.json';
$readmePath = $root . '/README.md';
$command = $argv[1] ?? 'check';

$read = function (string $path): string {
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException(sprintf('Unable to read %s.', $path));
    }

    return $contents;
};

$write = function (string $path, string $contents): void {
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException(sprintf('Unable to write %s.', $path));
    }
};

try {
    $manifest = PhpSupport::decodeManifest($read($manifestPath));

    if ($command === 'update') {
        $manifest = PhpSupport::online()->discover(new DateTimeImmutable('today', new DateTimeZone('UTC')));
        $composer = PhpSupport::updateComposer($read($composerPath), $manifest);
        $readme = PhpSupport::updateReadme($read($readmePath), $manifest);
        $write($manifestPath, PhpSupport::encodeManifest($manifest));
        $write($composerPath, $composer);
        $write($readmePath, $readme);
    } elseif ($command === 'check') {
        PhpSupport::assertSynchronised($read($composerPath), $read($readmePath), $manifest);
    } elseif ($command === 'matrix') {
        echo json_encode($manifest['phpVersions'], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    } else {
        throw new RuntimeException('Usage: php tools/php-support.php [check|matrix|update]');
    }

    printf(
        "PHP support is synchronised from %s through %s.\n",
        $manifest['minimumPhp'],
        $manifest['latestPhp'],
    );
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
