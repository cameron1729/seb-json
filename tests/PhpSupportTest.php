<?php

/*
 * SPDX-FileCopyrightText: 2026 Cameron Ball
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace cameron1729\SebJson\Tests;

use cameron1729\SebJson\Tools\PhpSupport;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PhpSupportTest extends TestCase
{
    private const DEVDOCS_REF = '1234567890abcdef1234567890abcdef12345678';

    public function testDiscoversCurrentStableReleasesAndNewestLts(): void
    {
        $support = new PhpSupport($this->fetcher());

        self::assertSame([
            'upstream' => [
                'php' => '8.5',
                'moodle' => '5.2',
            ],
            'minimumPhp' => '8.1',
            'phpVersions' => ['8.1', '8.2', '8.3', '8.4', '8.5'],
            'latestPhp' => '8.5',
            'moodleVersions' => [
                [
                    'version' => '4.5',
                    'minimumPhp' => '8.1',
                    'support' => 'lts',
                    'supportEnds' => '2027-10-04',
                ],
                [
                    'version' => '5.1',
                    'minimumPhp' => '8.2',
                    'support' => 'stable',
                    'supportEnds' => '2026-10-05',
                ],
                [
                    'version' => '5.2',
                    'minimumPhp' => '8.3',
                    'support' => 'stable',
                    'supportEnds' => '2027-04-19',
                ],
            ],
        ], $support->discover($this->date('2026-07-10')));
    }

    public function testNewLtsReplacesPreviousLtsAndExpiredStableRelease(): void
    {
        $support = new PhpSupport($this->fetcher(moodleTags: [
            '4.1',
            '4.5',
            '5.0',
            '5.1',
            '5.2',
            '5.3',
        ]));
        $manifest = $support->discover($this->date('2026-10-05'));

        self::assertSame('8.3', $manifest['minimumPhp']);
        self::assertSame(['8.3', '8.4', '8.5'], $manifest['phpVersions']);
        self::assertSame(['5.2', '5.3'], array_column($manifest['moodleVersions'], 'version'));
        self::assertSame(['stable', 'lts'], array_column($manifest['moodleVersions'], 'support'));
    }

    public function testNewestLtsRemainsSupportedAlongsideLaterStableReleases(): void
    {
        $support = new PhpSupport($this->fetcher(moodleTags: [
            '4.1',
            '4.5',
            '5.0',
            '5.1',
            '5.2',
            '5.3',
            '5.4',
            '5.5',
        ]));
        $manifest = $support->discover($this->date('2027-10-05'));

        self::assertSame(['5.3', '5.4', '5.5'], array_column($manifest['moodleVersions'], 'version'));
        self::assertSame(['lts', 'stable', 'stable'], array_column($manifest['moodleVersions'], 'support'));
    }

    public function testDiscoversOnlyStablePhpTags(): void
    {
        $support = new PhpSupport($this->fetcher(phpTags: [
            'refs/tags/php-8.1.0',
            'refs/tags/php-8.2.0',
            'refs/tags/php-8.3.0',
            'refs/tags/php-8.4.0',
            'refs/tags/php-8.5.8',
            'refs/tags/php-8.6.0alpha1',
            'refs/tags/php-8.6.0RC1',
            'refs/tags/php-8.6.0',
        ]));
        $manifest = $support->discover($this->date('2026-12-01'));

        self::assertSame('8.6', $manifest['latestPhp']);
        self::assertSame(['8.1', '8.2', '8.3', '8.4', '8.5', '8.6'], $manifest['phpVersions']);
    }

    public function testRejectsMoodleSourceWithoutMinimumPhpRequirement(): void
    {
        $support = new PhpSupport($this->fetcher(minimumPhp: ['4.5' => null]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Moodle 4.5 source does not declare its minimum PHP version.');

        $support->discover($this->date('2026-07-10'));
    }

    public function testSynchronisesComposerAndReadme(): void
    {
        $manifest = $this->manifest();
        $composer = <<<'JSON'
{
    "require": {
        "php": ">=8.2"
    }
}
JSON;
        $readme = '[![PHP >=8.2](https://img.shields.io/badge/'
            . 'PHP-%3E%3D8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)';

        $composer = PhpSupport::updateComposer($composer, $manifest);
        $readme = PhpSupport::updateReadme($readme, $manifest);

        self::assertStringContainsString('"php": ">=8.1"', $composer);
        self::assertStringContainsString('PHP >=8.1', $readme);
        self::assertStringContainsString('PHP-%3E%3D8.1-777BB4', $readme);
        PhpSupport::assertSynchronised($composer, $readme, $manifest);
    }

    public function testRejectsManifestWithMissingPhpVersion(): void
    {
        $manifest = $this->manifest();
        $manifest['phpVersions'] = ['8.1', '8.3', '8.4', '8.5'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PHP support range is missing a version after PHP 8.1.');

        PhpSupport::encodeManifest($manifest);
    }

    public function testRejectsManifestWithMoreThanOneLts(): void
    {
        $manifest = $this->manifest();
        $manifest['moodleVersions'][1] = [
            'version' => '5.1',
            'minimumPhp' => '8.2',
            'support' => 'lts',
            'supportEnds' => '2026-10-05',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must identify exactly one Moodle LTS release');

        PhpSupport::encodeManifest($manifest);
    }

    /**
     * @param list<string> $moodleTags
     * @param list<string>|null $phpTags
     * @param array<string, string|null> $minimumPhp
     * @return Closure(string): string
     */
    private function fetcher(
        array $moodleTags = ['4.1', '4.5', '4.6', '5.0', '5.1', '5.2'],
        ?array $phpTags = null,
        array $minimumPhp = [],
    ): Closure {
        $phpTags ??= [
            'refs/tags/php-8.1.0',
            'refs/tags/php-8.2.0',
            'refs/tags/php-8.3.0',
            'refs/tags/php-8.4.0',
            'refs/tags/php-8.5.8',
            'refs/tags/php-8.6.0alpha1',
        ];
        $requirements = array_replace([
            '4.1' => '7.4',
            '4.5' => '8.1',
            '5.0' => '8.2',
            '5.1' => '8.2',
            '5.2' => '8.3',
            '5.3' => '8.3',
            '5.4' => '8.4',
            '5.5' => '8.4',
        ], $minimumPhp);
        $moodleRefs = array_map(
            fn(string $version): string => sprintf('refs/tags/v%s.0', $version),
            $moodleTags,
        );
        $moodleRefs[] = 'refs/tags/v5.3.0-beta';
        $moodleRefs[] = 'refs/tags/v5.2.1';

        return function (string $url) use ($phpTags, $moodleRefs, $requirements): string {
            if (str_ends_with($url, '/commits/main')) {
                return json_encode(['sha' => self::DEVDOCS_REF], JSON_THROW_ON_ERROR);
            }

            if (str_ends_with($url, '/data/versions.json')) {
                return json_encode($this->releaseData(), JSON_THROW_ON_ERROR);
            }

            if (str_ends_with($url, '/matching-refs/tags/php-')) {
                return json_encode(self::tagData($phpTags), JSON_THROW_ON_ERROR);
            }

            if (str_ends_with($url, '/matching-refs/tags/v')) {
                return json_encode(self::tagData($moodleRefs), JSON_THROW_ON_ERROR);
            }

            if (preg_match('~/v(\d+\.\d+)\.0/(public/)?lib/phpminimumversionlib\.php$~', $url, $matches) === 1) {
                $version = $matches[1];
                $usesPublicDirectory = ($matches[2] ?? '') === 'public/';

                if ($usesPublicDirectory !== version_compare($version, '5.1', '>=')) {
                    throw new RuntimeException(sprintf('Wrong source path requested for Moodle %s.', $version));
                }

                $requirement = $requirements[$version] ?? null;

                return $requirement === null
                    ? '<?php'
                    : sprintf("<?php\n\n    \$minimumversion = '%s.0';\n", $requirement);
            }

            throw new RuntimeException(sprintf('Unexpected test URL: %s', $url));
        };
    }

    /**
     * @param list<string> $refs
     * @return list<array{ref: string}>
     */
    private static function tagData(array $refs): array
    {
        return array_map(fn(string $ref): array => ['ref' => $ref], $refs);
    }

    /** @return array{versions: list<array<string, bool|string>>} */
    private function releaseData(): array
    {
        return [
            'versions' => [
                $this->release('4.1', '28 November 2022', '11 December 2023', '8 December 2025', true),
                $this->release('4.5', '7 October 2024', '6 October 2025', '4 October 2027', true),
                $this->release('4.6', '20 December 2024', '14 April 2025', '14 April 2025', experimental: true),
                $this->release('5.0', '14 April 2025', '20 April 2026', '5 October 2026'),
                $this->release('5.1', '6 October 2025', '5 October 2026', '19 April 2027'),
                $this->release('5.2', '20 April 2026', '19 April 2027', '4 October 2027'),
                $this->release('5.3', '5 October 2026', '4 October 2027', '1 October 2029', true),
                $this->release('5.4', '19 April 2027', '17 April 2028', '2 October 2028'),
                $this->release('5.5', '4 October 2027', '2 October 2028', '16 April 2029'),
            ],
        ];
    }

    /** @return array<string, bool|string> */
    private function release(
        string $name,
        string $released,
        string $generalEnds,
        string $securityEnds,
        bool $isLts = false,
        bool $experimental = false,
    ): array {
        return [
            'name' => $name,
            'releaseDate' => $released,
            'generalEndDate' => $generalEnds,
            'securityEndDate' => $securityEnds,
            'isLTS' => $isLts,
            'isExperimental' => $experimental,
        ];
    }

    /**
     * @return array{
     *     upstream: array{php: string, moodle: string},
     *     minimumPhp: string,
     *     latestPhp: string,
     *     phpVersions: list<string>,
     *     moodleVersions: list<array{
     *         version: string,
     *         minimumPhp: string,
     *         support: 'lts'|'stable',
     *         supportEnds: string
     *     }>
     * }
     */
    private function manifest(): array
    {
        return [
            'upstream' => [
                'php' => '8.5',
                'moodle' => '5.2',
            ],
            'minimumPhp' => '8.1',
            'phpVersions' => ['8.1', '8.2', '8.3', '8.4', '8.5'],
            'latestPhp' => '8.5',
            'moodleVersions' => [
                [
                    'version' => '4.5',
                    'minimumPhp' => '8.1',
                    'support' => 'lts',
                    'supportEnds' => '2027-10-04',
                ],
                [
                    'version' => '5.1',
                    'minimumPhp' => '8.2',
                    'support' => 'stable',
                    'supportEnds' => '2026-10-05',
                ],
                [
                    'version' => '5.2',
                    'minimumPhp' => '8.3',
                    'support' => 'stable',
                    'supportEnds' => '2027-04-19',
                ],
            ],
        ];
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
