<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the compatibility table in README.md honest.
 *
 * The table tells shop owners which OXID and PHP versions this module supports. Nothing
 * otherwise connects it to the CI matrix that actually proves those combinations, so the two
 * would drift the moment a version is added to one and not the other - and the table is the
 * one people act on.
 *
 * This test is the connection. It parses both and fails on any difference, and because it
 * lives in the unit suite it runs in every matrix job rather than as a separate gate.
 *
 * It guards composer.json's php constraint against the same matrix, because that constraint -
 * not the table - is what Composer actually resolves against. The two drifted once already, on a sibling module:
 * its OXID 7 branch advertised "^7.4 || ^8.0" while its CI ran 8.0 upwards, so a PHP 7.4 shop
 * could have resolved the Twig-only release line and rendered nothing.
 */
class CompatibilityTableTest extends TestCase
{
    private const README = __DIR__ . '/../../README.md';
    private const WORKFLOW = __DIR__ . '/../../.github/workflows/ci.yml';
    private const COMPOSER = __DIR__ . '/../../composer.json';

    private const TABLE_START = '<!-- ci-matrix:start -->';
    private const TABLE_END = '<!-- ci-matrix:end -->';

    public function testReadmeListsExactlyWhatCiTests(): void
    {
        $fromCi = $this->readCiMatrix();
        $fromReadme = $this->readReadmeTable();

        $this->assertNotEmpty($fromCi, 'no matrix entries found in the workflow');
        $this->assertSame(
            $fromCi,
            $fromReadme,
            "README.md and the CI matrix disagree.\n"
            . "Whatever changed, change it in both - the table is a promise, the matrix is the proof."
        );
    }

    public function testEveryPhpVersionCiTestsIsAllowedByComposer(): void
    {
        $constraint = $this->readPhpConstraint();
        $ranges = $this->parseCaretRanges($constraint);

        $rejected = [];
        foreach ($this->testedPhpVersions() as $version) {
            if (!$this->isAllowed($version, $ranges)) {
                $rejected[] = $version;
            }
        }

        $this->assertSame(
            [],
            $rejected,
            "composer.json requires php \"$constraint\", which excludes PHP versions the CI matrix "
            . "actually tests.
Composer would refuse to install this module on a shop running them."
        );
    }

    /**
     * The half that matters most, and the one that failed silently before it existed: a
     * constraint may not reach *below* the lowest PHP version this branch is tested on. Allowing
     * more than is proven is not generosity - it is how a release line ends up installable on a
     * shop it cannot run on.
     */
    public function testComposerDoesNotAllowAnOlderPhpThanCiTests(): void
    {
        $constraint = $this->readPhpConstraint();
        $tested = $this->testedPhpVersions();

        $lowestAllowed = null;
        foreach ($this->parseCaretRanges($constraint) as $range) {
            if ($lowestAllowed === null || version_compare($range[0], $lowestAllowed, '<')) {
                $lowestAllowed = $range[0];
            }
        }

        $this->assertSame(
            $tested[0],
            $lowestAllowed,
            "composer.json requires php \"$constraint\", so the oldest PHP it admits is "
            . "$lowestAllowed, but the oldest one CI proves is {$tested[0]}."
        );
    }

    private function readPhpConstraint(): string
    {
        $raw = file_get_contents(self::COMPOSER);
        $this->assertNotFalse($raw, 'composer.json not readable');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'composer.json is not valid JSON');
        $this->assertArrayHasKey('php', $decoded['require'] ?? [], 'composer.json declares no php requirement');

        return $decoded['require']['php'];
    }

    /**
     * Only caret ranges joined by "||" are understood - the shape this branch uses. Anything
     * else fails loudly rather than being waved through, because a constraint this test cannot
     * read is a constraint it cannot guard.
     *
     * @return array<int, string[]> list of [minimum, exclusive maximum]
     */
    private function parseCaretRanges(string $constraint): array
    {
        $ranges = [];
        foreach (explode('||', $constraint) as $clause) {
            $clause = trim($clause);

            $this->assertSame(
                1,
                preg_match('/^\^(\d+)\.(\d+)$/', $clause, $parts),
                "cannot read the php constraint clause \"$clause\" - this test only understands "
                . 'caret ranges such as "^8.0", joined by "||". Teach it the new shape.'
            );

            $ranges[] = [$parts[1] . '.' . $parts[2], ((int) $parts[1] + 1) . '.0'];
        }

        return $ranges;
    }

    /**
     * @param array<int, string[]> $ranges
     */
    private function isAllowed(string $version, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (version_compare($version, $range[0], '>=') && version_compare($version, $range[1], '<')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[] every PHP version in the CI matrix, unique and sorted ascending
     */
    private function testedPhpVersions(): array
    {
        $versions = [];
        foreach ($this->readCiMatrix() as $phpVersions) {
            $versions = array_merge($versions, $phpVersions);
        }

        $versions = array_unique($versions);
        usort($versions, 'version_compare');

        $this->assertNotEmpty($versions, 'no PHP versions found in the CI matrix');

        return array_values($versions);
    }

    /**
     * @return array<string, string[]> OXID version => PHP versions, both sorted
     */
    private function readCiMatrix(): array
    {
        $workflow = file_get_contents(self::WORKFLOW);
        $this->assertNotFalse($workflow, 'workflow file not readable: ' . self::WORKFLOW);

        preg_match_all(
            "/-\s*\{\s*oxid:\s*'([^']+)'\s*,\s*php:\s*'([^']+)'\s*\}/",
            $workflow,
            $matches,
            PREG_SET_ORDER
        );

        $matrix = [];
        foreach ($matches as $match) {
            $matrix[$match[1]][] = $match[2];
        }

        return $this->normalise($matrix);
    }

    /**
     * @return array<string, string[]> OXID version => PHP versions, both sorted
     */
    private function readReadmeTable(): array
    {
        $readme = file_get_contents(self::README);
        $this->assertNotFalse($readme, 'README.md not readable');

        $start = strpos($readme, self::TABLE_START);
        $end = strpos($readme, self::TABLE_END);
        $this->assertNotFalse($start, 'marker ' . self::TABLE_START . ' missing from README.md');
        $this->assertNotFalse($end, 'marker ' . self::TABLE_END . ' missing from README.md');

        $block = substr($readme, $start, $end - $start);

        $matrix = [];
        foreach (explode("\n", $block) as $line) {
            // | 7.3 | 8.2, 8.3, 8.4 |   - the header and separator rows do not match
            if (preg_match('/^\|\s*(\d+\.\d+)\s*\|\s*([0-9., ]+?)\s*\|/', trim($line), $row) !== 1) {
                continue;
            }

            $matrix[$row[1]] = array_map('trim', explode(',', $row[2]));
        }

        return $this->normalise($matrix);
    }

    /**
     * @param array<string, string[]> $matrix
     * @return array<string, string[]>
     */
    private function normalise(array $matrix): array
    {
        foreach ($matrix as $oxid => $phpVersions) {
            $phpVersions = array_unique($phpVersions);
            usort($phpVersions, 'version_compare');
            $matrix[$oxid] = array_values($phpVersions);
        }

        uksort($matrix, 'version_compare');

        return $matrix;
    }
}
