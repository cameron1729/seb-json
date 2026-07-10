<?php

/*
 * SPDX-FileCopyrightText: 2026 Cameron Ball
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace cameron1729\SebJson\Tools;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

/**
 * Discover and synchronise the PHP versions supported by this repository.
 *
 * @phpstan-type MoodleVersion array{
 *     version: string,
 *     minimumPhp: string,
 *     support: 'lts'|'stable',
 *     supportEnds: string
 * }
 * @phpstan-type Upstream array{
 *     php: string,
 *     moodle: string
 * }
 * @phpstan-type MoodleRelease array{
 *     version: string,
 *     generalEnds: string,
 *     securityEnds: string,
 *     isLts: bool
 * }
 * @phpstan-type Manifest array{
 *     upstream: Upstream,
 *     minimumPhp: string,
 *     latestPhp: string,
 *     phpVersions: list<string>,
 *     moodleVersions: list<MoodleVersion>
 * }
 */
final class PhpSupport
{
    private const MOODLE_DEVDOCS_REF_URL = 'https://api.github.com/repos/moodle/devdocs/commits/main';
    private const MOODLE_DEVDOCS_RAW_URL = 'https://raw.githubusercontent.com/moodle/devdocs/%s/';
    private const MOODLE_SOURCE_URL = 'https://raw.githubusercontent.com/moodle/moodle/v%s.0/';
    private const MOODLE_TAGS_URL = 'https://api.github.com/repos/moodle/moodle/git/matching-refs/tags/v';
    private const PHP_TAGS_URL = 'https://api.github.com/repos/php/php-src/git/matching-refs/tags/php-';

    /** @var Closure(string): string */
    private Closure $fetch;

    /** @param Closure(string): string $fetch */
    public function __construct(Closure $fetch)
    {
        $this->fetch = $fetch;
    }

    public static function online(): self
    {
        return new self(fn(string $url): string => self::fetchUrl($url));
    }

    /**
     * @return Manifest
     */
    public function discover(DateTimeImmutable $today): array
    {
        $devdocsRef = $this->devdocsRef();
        $releasedMoodleVersions = $this->tagVersions(
            self::MOODLE_TAGS_URL,
            '/^refs\/tags\/v(\d+\.\d+)\.0$/',
            'Moodle tags',
        );
        $latestMoodle = end($releasedMoodleVersions);

        if ($latestMoodle === false) {
            throw new RuntimeException('Moodle tags do not contain a stable Moodle release.');
        }

        $moodleVersions = $this->moodleVersions($devdocsRef, $today, $releasedMoodleVersions);
        $minimumPhp = self::minimumPhp($moodleVersions);
        $phpVersions = $this->tagVersions(
            self::PHP_TAGS_URL,
            '/^refs\/tags\/php-(\d+\.\d+)\.\d+$/',
            'PHP tags',
        );
        $latestPhp = end($phpVersions);

        if ($latestPhp === false) {
            throw new RuntimeException('PHP tags do not contain a stable PHP release.');
        }

        return self::validateManifest([
            'upstream' => [
                'php' => $latestPhp,
                'moodle' => $latestMoodle,
            ],
            'minimumPhp' => $minimumPhp,
            'phpVersions' => self::phpVersions($minimumPhp, $latestPhp, $phpVersions),
            'latestPhp' => $latestPhp,
            'moodleVersions' => $moodleVersions,
        ]);
    }

    /** @return Manifest */
    public static function decodeManifest(string $json): array
    {
        $data = self::decodeJson($json, 'PHP support manifest');
        $upstream = self::decodeUpstream($data['upstream'] ?? null);
        $minimumPhp = self::stringField($data, 'minimumPhp', 'PHP support manifest');
        $latestPhp = self::stringField($data, 'latestPhp', 'PHP support manifest');
        $phpVersions = $data['phpVersions'] ?? null;
        $moodleVersions = $data['moodleVersions'] ?? null;

        if (!is_array($phpVersions) || !array_is_list($phpVersions)) {
            throw new RuntimeException('PHP support manifest must contain a phpVersions list.');
        }

        if (!is_array($moodleVersions) || !array_is_list($moodleVersions)) {
            throw new RuntimeException('PHP support manifest must contain a moodleVersions list.');
        }

        $versions = array_map(
            fn(mixed $version): string => is_string($version)
                ? $version
                : throw new RuntimeException('PHP support manifest contains an invalid PHP version.'),
            $phpVersions,
        );
        $releases = array_map(
            fn(mixed $release): array => self::decodeMoodleVersion($release),
            $moodleVersions,
        );

        return self::validateManifest([
            'upstream' => $upstream,
            'minimumPhp' => $minimumPhp,
            'phpVersions' => $versions,
            'latestPhp' => $latestPhp,
            'moodleVersions' => $releases,
        ]);
    }

    /** @param Manifest $manifest */
    public static function encodeManifest(array $manifest): string
    {
        return json_encode(
            self::validateManifest($manifest),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }

    /** @param Manifest $manifest */
    public static function updateComposer(string $json, array $manifest): string
    {
        $composer = self::decodeJson($json, 'composer.json');
        $require = $composer['require'] ?? null;

        if (!is_array($require)) {
            throw new RuntimeException('composer.json must contain a require object.');
        }

        $require['php'] = '>=' . $manifest['minimumPhp'];
        $composer['require'] = $require;

        return json_encode(
            $composer,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }

    /** @param Manifest $manifest */
    public static function updateReadme(string $readme, array $manifest): string
    {
        $badgePattern = '~\[!\[PHP >=\d+\.\d+\]\(https://img\.shields\.io/badge/'
            . 'PHP-%3E%3D\d+\.\d+-777BB4\?logo=php&logoColor=white\)\]'
            . '\(https://www\.php\.net/\)~';
        $badge = sprintf(
            '[![PHP >=%1$s](https://img.shields.io/badge/'
                . 'PHP-%%3E%%3D%1$s-777BB4?logo=php&logoColor=white)](https://www.php.net/)',
            $manifest['minimumPhp'],
        );
        $readme = preg_replace($badgePattern, $badge, $readme, 1, $badgeCount);

        if ($readme === null || $badgeCount !== 1) {
            throw new RuntimeException('README.md must contain exactly one generated PHP badge.');
        }

        return $readme;
    }

    /** @param Manifest $manifest */
    public static function assertSynchronised(string $composerJson, string $readme, array $manifest): void
    {
        $composer = self::decodeJson($composerJson, 'composer.json');
        $require = $composer['require'] ?? null;

        if (!is_array($require) || ($require['php'] ?? null) !== '>=' . $manifest['minimumPhp']) {
            throw new RuntimeException('composer.json does not match the generated PHP support manifest.');
        }

        if (self::updateReadme($readme, $manifest) !== $readme) {
            throw new RuntimeException('README.md does not match the generated PHP support manifest.');
        }
    }

    /**
     * @param Manifest $manifest
     * @return Manifest
     */
    private static function validateManifest(array $manifest): array
    {
        self::assertMinorVersion($manifest['upstream']['php'], 'PHP support manifest upstream PHP release');
        self::assertMinorVersion($manifest['upstream']['moodle'], 'PHP support manifest upstream Moodle release');
        $versions = $manifest['phpVersions'];

        if ($versions === []) {
            throw new RuntimeException('PHP support manifest cannot contain an empty PHP version list.');
        }

        foreach ($versions as $version) {
            self::assertMinorVersion($version, 'PHP support manifest');
        }

        $sorted = array_values(array_unique($versions));
        usort($sorted, fn(string $left, string $right): int => version_compare($left, $right));

        if ($versions !== $sorted) {
            throw new RuntimeException('PHP support manifest versions must be unique and ordered.');
        }

        if ($versions[0] !== $manifest['minimumPhp']) {
            throw new RuntimeException('PHP support manifest minimum does not match its version list.');
        }

        if ($versions[array_key_last($versions)] !== $manifest['latestPhp']) {
            throw new RuntimeException('PHP support manifest latest version does not match its version list.');
        }

        self::assertContinuous($versions);

        if ($manifest['moodleVersions'] === []) {
            throw new RuntimeException('PHP support manifest must contain at least one supported Moodle release.');
        }

        $moodleVersions = array_column($manifest['moodleVersions'], 'version');
        $sortedMoodleVersions = array_values(array_unique($moodleVersions));
        usort($sortedMoodleVersions, fn(string $left, string $right): int => version_compare($left, $right));

        if ($moodleVersions !== $sortedMoodleVersions) {
            throw new RuntimeException('Supported Moodle releases must be unique and ordered.');
        }

        $lts = array_filter(
            $manifest['moodleVersions'],
            fn(array $release): bool => $release['support'] === 'lts',
        );

        if (count($lts) !== 1) {
            throw new RuntimeException('PHP support manifest must identify exactly one Moodle LTS release.');
        }

        if (self::minimumPhp($manifest['moodleVersions']) !== $manifest['minimumPhp']) {
            throw new RuntimeException('PHP support manifest minimum does not match its Moodle releases.');
        }

        return $manifest;
    }

    private function devdocsRef(): string
    {
        $data = self::decodeJson(($this->fetch)(self::MOODLE_DEVDOCS_REF_URL), 'Moodle devdocs reference');
        $ref = self::stringField($data, 'sha', 'Moodle devdocs reference');

        if (preg_match('/^[0-9a-f]{40}$/', $ref) !== 1) {
            throw new RuntimeException('Moodle devdocs returned an invalid Git commit reference.');
        }

        return $ref;
    }

    /**
     * @param list<string> $releasedVersions
     * @return list<MoodleVersion>
     */
    private function moodleVersions(string $ref, DateTimeImmutable $today, array $releasedVersions): array
    {
        $baseUrl = sprintf(self::MOODLE_DEVDOCS_RAW_URL, $ref);
        $data = self::decodeJson(($this->fetch)($baseUrl . 'data/versions.json'), 'Moodle release data');
        $versions = $data['versions'] ?? null;

        if (!is_array($versions) || !array_is_list($versions)) {
            throw new RuntimeException('Moodle release data must contain a versions list.');
        }

        $date = $today->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $tags = array_fill_keys($releasedVersions, true);
        /** @var list<MoodleRelease> $releases */
        $releases = [];

        foreach ($versions as $release) {
            if (!is_array($release)) {
                throw new RuntimeException('Moodle release data contains an invalid release.');
            }

            if (($release['isExperimental'] ?? false) === true) {
                continue;
            }

            $version = self::stringField($release, 'name', 'Moodle release data');
            $released = self::moodleDate(self::stringField($release, 'releaseDate', 'Moodle release data'));
            $generalEnds = self::moodleDate(
                self::stringField($release, 'generalEndDate', 'Moodle release data'),
            );
            $securityEnds = self::moodleDate(
                self::stringField($release, 'securityEndDate', 'Moodle release data'),
            );

            if (!isset($tags[$version]) || $released > $date) {
                continue;
            }

            $releases[] = [
                'version' => $version,
                'generalEnds' => $generalEnds,
                'securityEnds' => $securityEnds,
                'isLts' => ($release['isLTS'] ?? false) === true,
            ];
        }

        $lts = array_values(array_filter(
            $releases,
            fn(array $release): bool => $release['isLts'] && $release['securityEnds'] >= $date,
        ));

        if ($lts === []) {
            throw new RuntimeException('Moodle devdocs does not identify a currently supported LTS release.');
        }

        usort($lts, fn(array $left, array $right): int => version_compare($left['version'], $right['version']));
        $latestLts = $lts[array_key_last($lts)];
        $selected = array_filter(
            $releases,
            fn(array $release): bool => $release['generalEnds'] > $date,
        );
        $selected[] = $latestLts;
        $selected = array_values(array_column($selected, null, 'version'));
        usort($selected, fn(array $left, array $right): int => version_compare($left['version'], $right['version']));

        return array_map(
            fn(array $release): array => [
                'version' => $release['version'],
                'minimumPhp' => $this->moodleMinimumPhp($release['version']),
                'support' => $release['version'] === $latestLts['version'] ? 'lts' : 'stable',
                'supportEnds' => $release['version'] === $latestLts['version']
                    ? $release['securityEnds']
                    : $release['generalEnds'],
            ],
            $selected,
        );
    }

    private function moodleMinimumPhp(string $version): string
    {
        // Moodle moved its web root into public/ in 5.1.
        $path = version_compare($version, '5.1', '>=')
            ? 'public/lib/phpminimumversionlib.php'
            : 'lib/phpminimumversionlib.php';
        $url = sprintf(self::MOODLE_SOURCE_URL, $version) . $path;
        $source = ($this->fetch)($url);

        if (preg_match('/\$minimumversion\s*=\s*\'(\d+\.\d+)(?:\.\d+)?\';/', $source, $matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Moodle %s source does not declare its minimum PHP version.',
                $version,
            ));
        }

        return $matches[1];
    }

    /** @return list<string> */
    private function tagVersions(string $url, string $pattern, string $source): array
    {
        $tags = self::decodeJsonList(($this->fetch)($url), $source);
        $versions = array_filter(array_map(
            function (mixed $tag) use ($pattern, $source): ?string {
                if (!is_array($tag)) {
                    throw new RuntimeException(sprintf('%s contains an invalid Git reference.', $source));
                }

                $ref = self::stringField($tag, 'ref', $source);

                return preg_match($pattern, $ref, $matches) === 1 ? $matches[1] : null;
            },
            $tags,
        ));
        $versions = array_values(array_unique($versions));
        usort($versions, fn(string $left, string $right): int => version_compare($left, $right));

        return $versions;
    }

    /** @param list<MoodleVersion> $releases */
    private static function minimumPhp(array $releases): string
    {
        if ($releases === []) {
            throw new RuntimeException('Cannot calculate PHP support without a Moodle release.');
        }

        return array_reduce(
            array_slice($releases, 1),
            fn(string $minimum, array $release): string => version_compare($release['minimumPhp'], $minimum, '<')
                ? $release['minimumPhp']
                : $minimum,
            $releases[0]['minimumPhp'],
        );
    }

    /**
     * @param list<string> $released
     * @return list<string>
     */
    private static function phpVersions(string $minimum, string $latest, array $released): array
    {
        $versions = array_filter(
            array_unique([$minimum, ...$released]),
            fn(string $version): bool => version_compare($version, $minimum, '>=')
                && version_compare($version, $latest, '<='),
        );
        $versions = array_values($versions);
        usort($versions, fn(string $left, string $right): int => version_compare($left, $right));
        self::assertContinuous($versions);

        return $versions;
    }

    /** @param list<string> $versions */
    private static function assertContinuous(array $versions): void
    {
        $previous = null;

        foreach ($versions as $version) {
            if ($previous === null) {
                $previous = $version;
                continue;
            }

            [$major, $minor] = array_map(intval(...), explode('.', $previous));
            [$nextMajor, $nextMinor] = array_map(intval(...), explode('.', $version));
            $sameMajor = $nextMajor === $major && $nextMinor === $minor + 1;
            $nextMajorVersion = $nextMajor === $major + 1 && $nextMinor === 0;

            if (!$sameMajor && !$nextMajorVersion) {
                throw new RuntimeException(sprintf('PHP support range is missing a version after PHP %s.', $previous));
            }

            $previous = $version;
        }
    }

    /** @return array<mixed, mixed> */
    private static function decodeJson(string $json, string $source): array
    {
        $data = self::decodeJsonValue($json, $source);

        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException(sprintf('%s must contain a JSON object.', $source));
        }

        return $data;
    }

    /** @return list<mixed> */
    private static function decodeJsonList(string $json, string $source): array
    {
        $data = self::decodeJsonValue($json, $source);

        if (!is_array($data) || !array_is_list($data)) {
            throw new RuntimeException(sprintf('%s must contain a JSON list.', $source));
        }

        return $data;
    }

    private static function decodeJsonValue(string $json, string $source): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('%s did not contain valid JSON.', $source), 0, $exception);
        }
    }

    /** @param array<mixed, mixed> $data */
    private static function stringField(array $data, string $field, string $source): string
    {
        $value = $data[$field] ?? null;

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('%s must contain a non-empty %s string.', $source, $field));
        }

        return $value;
    }

    /** @return Upstream */
    private static function decodeUpstream(mixed $upstream): array
    {
        if (!is_array($upstream)) {
            throw new RuntimeException('PHP support manifest must contain an upstream object.');
        }

        return [
            'php' => self::stringField($upstream, 'php', 'PHP support manifest upstream releases'),
            'moodle' => self::stringField($upstream, 'moodle', 'PHP support manifest upstream releases'),
        ];
    }

    /** @return MoodleVersion */
    private static function decodeMoodleVersion(mixed $release): array
    {
        if (!is_array($release)) {
            throw new RuntimeException('PHP support manifest contains an invalid Moodle release.');
        }

        $version = self::stringField($release, 'version', 'PHP support manifest');
        $minimumPhp = self::stringField($release, 'minimumPhp', 'PHP support manifest');
        $support = self::stringField($release, 'support', 'PHP support manifest');
        $supportEnds = self::stringField($release, 'supportEnds', 'PHP support manifest');

        if ($support !== 'lts' && $support !== 'stable') {
            throw new RuntimeException('PHP support manifest contains an invalid Moodle support type.');
        }

        self::assertMinorVersion($version, 'PHP support manifest Moodle release');
        self::assertMinorVersion($minimumPhp, 'PHP support manifest');
        self::isoDate($supportEnds);

        return [
            'version' => $version,
            'minimumPhp' => $minimumPhp,
            'support' => $support,
            'supportEnds' => $supportEnds,
        ];
    }

    private static function assertMinorVersion(string $version, string $source): void
    {
        if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
            throw new RuntimeException(sprintf('%s contains an invalid minor version.', $source));
        }
    }

    private static function moodleDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!j F Y', $date, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException(sprintf('Moodle release data contains an invalid date: %s.', $date));
        }

        return $parsed->format('Y-m-d');
    }

    private static function isoDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException(sprintf('PHP support manifest contains an invalid date: %s.', $date));
        }

        return $parsed;
    }

    private static function fetchUrl(string $url): string
    {
        $token = getenv('GITHUB_TOKEN');
        $authorisation = str_starts_with($url, 'https://api.github.com/') && is_string($token) && $token !== ''
            ? sprintf("Authorization: Bearer %s\r\n", $token)
            : '';
        $context = stream_context_create([
            'http' => [
                'header' => "Accept: application/json\r\n{$authorisation}User-Agent: cameron1729/seb-json\r\n",
                'timeout' => 30,
            ],
        ]);
        $contents = @file_get_contents($url, false, $context);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to fetch %s.', $url));
        }

        return $contents;
    }
}
