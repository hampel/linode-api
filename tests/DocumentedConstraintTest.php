<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The README must recommend a constraint that includes the current release.
 *
 * THIS EXISTS BECAUSE AN INTENTION WAS NOT ENOUGH, TWICE. `0.2.0` shipped telling readers to
 * write `^0.1`, which resolves to `0.1.0` and therefore excludes the release whose README it
 * is - and a paragraph underneath reassured them the newer release would not arrive unasked,
 * which is the half that stops them checking the CHANGELOG. That was fixed in `0.2.1`, and the
 * fix included writing the rule into CLAUDE.md as a command to run on every bump.
 *
 * Three commits later the same mistake was made again, caught only by a manual check while
 * tagging. So the rule is a test now. A release cannot recommend a constraint that excludes
 * itself while this passes, and nobody has to remember anything.
 */
final class DocumentedConstraintTest extends BaseTestCase
{
    public function test_the_readme_recommends_a_constraint_that_includes_this_release(): void
    {
        $version = self::currentVersion();
        $expected = self::caretFor($version);

        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString(
            sprintf('`%s` is the constraint to write', $expected),
            $readme,
            sprintf(
                'The CHANGELOG\'s newest release is %s, so the README should recommend %s. A '
                    . 'constraint naming an older minor resolves to that minor and excludes the '
                    . 'release the reader is looking at.',
                $version,
                $expected
            )
        );
    }

    /**
     * Every worked example of the caret rule in the README has to agree with it too - the
     * reassurance underneath the recommendation is what stopped the last reader checking.
     */
    public function test_the_readme_does_not_explain_a_superseded_constraint(): void
    {
        $expected = self::caretFor(self::currentVersion());
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        preg_match_all('/`(\^\d+\.\d+)` is `>=/', $readme, $matches);

        // Asserted as a set rather than in a loop. A loop over no matches runs no assertion at
        // all, and PHPUnit reports that as risky rather than passing - which is correct, and is
        // how this was caught going vacuous the moment the README stopped carrying an example.
        // There need not be one; if there is, it must not name a superseded minor.
        $this->assertSame(
            [],
            array_values(array_diff(array_unique($matches[1]), [$expected])),
            'a caret example in the README names a constraint other than the current one'
        );
    }

    /**
     * The newest version heading in the CHANGELOG, which is where the release number lives -
     * composer.json carries none, Packagist deriving it from the tag.
     */
    private static function currentVersion(): string
    {
        $changelog = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');

        if (preg_match('/^(\d+\.\d+\.\d+) \(\d{4}-\d{2}-\d{2}\)$/m', $changelog, $m) !== 1) {
            self::fail('No dated release heading found in CHANGELOG.md');
        }

        return $m[1];
    }

    /**
     * Composer's caret pins to the minor below 1.0 and to the major at or above it.
     */
    private static function caretFor(string $version): string
    {
        [$major, $minor] = array_map(intval(...), explode('.', $version));

        return $major === 0 ? sprintf('^0.%d', $minor) : sprintf('^%d.0', $major);
    }
}
