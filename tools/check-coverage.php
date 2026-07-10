<?php

/*
 * SPDX-FileCopyrightText: 2026 Cameron Ball
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

if (count($arguments) !== 3) {
    fwrite(STDERR, "Usage: php tools/check-coverage.php <clover.xml> <minimum-percent>\n");
    exit(2);
}

$coveragefile = $arguments[1];
$minimum = (float)$arguments[2];

if (!is_file($coveragefile)) {
    fwrite(STDERR, "Coverage file not found: {$coveragefile}\n");
    exit(2);
}

$coverage = simplexml_load_file($coveragefile);

if ($coverage === false || !isset($coverage->project->metrics)) {
    fwrite(STDERR, "Could not read Clover coverage metrics from {$coveragefile}\n");
    exit(2);
}

$metrics = $coverage->project->metrics;
$statements = (int)$metrics['statements'];
$coveredstatements = (int)$metrics['coveredstatements'];

if ($statements === 0) {
    fwrite(STDERR, "Coverage report contains no statements.\n");
    exit(1);
}

$percentage = ($coveredstatements / $statements) * 100.0;

printf("Line coverage: %.2f%% (%d/%d statements)\n", $percentage, $coveredstatements, $statements);

if ($percentage < $minimum) {
    fwrite(STDERR, sprintf("Coverage is below %.2f%%.\n", $minimum));
    exit(1);
}
