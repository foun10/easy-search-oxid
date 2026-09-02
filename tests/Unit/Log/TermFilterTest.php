<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Log;

use foun10\EasySearch\Log\TermFilter;
use PHPUnit\Framework\TestCase;

/**
 * Decides what is not a search term.
 *
 * This runs at both ends - the writer never stores a matched input, and the
 * backend drops whatever was stored before the filter existed - so it has two
 * ways to be wrong. Letting a payload through puts attacker-controlled text on
 * a merchant's screen; rejecting a real term makes it vanish from the report
 * that is supposed to explain what customers looked for.
 *
 * The second failure is the one worth guarding hardest, because nobody notices
 * it. Hence the false-positive cases below carry the odd-looking real searches
 * from a clothing catalogue: "3/4", "36c", "s/m".
 */
class TermFilterTest extends TestCase
{
    private TermFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new TermFilter();
    }

    /**
     * @dataProvider realSearchProvider
     */
    public function testRealSearchTermsPassThrough(string $term): void
    {
        $this->assertNull($this->filter->check($term), "'$term' is a real search and must be kept");
        $this->assertFalse($this->filter->isSuspicious($term));
    }

    public function realSearchProvider(): array
    {
        return [
            'plain word'        => ['spitze'],
            'two words'         => ['bügel bh'],
            'size with letter'  => ['36c'],
            'fraction sleeve'   => ['3/4'],
            'slashed sizes'     => ['s/m'],
            'article number'    => ['ART-12345'],
            'brand with dot'    => ['a.b. mode'],
            'percent'           => ['70% baumwolle'],
            'ampersand'         => ['rock & bluse'],
            'apostrophe'        => ["men's"],
        ];
    }

    /**
     * @dataProvider payloadProvider
     */
    public function testPayloadsAreRejectedWithTheRightReason(string $term, string $expected): void
    {
        $this->assertSame($expected, $this->filter->check($term));
        $this->assertTrue($this->filter->isSuspicious($term));
    }

    public function payloadProvider(): array
    {
        return [
            'script tag'      => ['<script>alert(1)</script>', TermFilter::REASON_MARKUP],
            'javascript uri'  => ['javascript:alert(1)', TermFilter::REASON_MARKUP],
            'event handler'   => ['x onerror=alert(1)', TermFilter::REASON_MARKUP],
            'html entity'     => ['&#x3c;script', TermFilter::REASON_MARKUP],
            'union select'    => ["1' union select 1,2", TermFilter::REASON_SQL],
            'select from'     => ['select name from oxarticles', TermFilter::REASON_SQL],
            'drop table'      => ['drop table oxuser', TermFilter::REASON_SQL],
            'schema probe'    => ['information_schema', TermFilter::REASON_SQL],
            'time based'      => ['sleep(5)', TermFilter::REASON_SQL],
            'traversal'       => ['../../etc/passwd', TermFilter::REASON_PATH],
            'wrapper'         => ['php://input', TermFilter::REASON_PATH],
            'twig'            => ['{{7*7}}', TermFilter::REASON_TEMPLATE],
            'shell expansion' => ['$(id)', TermFilter::REASON_SHELL],
            'backticks'       => ['`id`', TermFilter::REASON_SHELL],
            'piped fetch'     => ['x | curl example.com', TermFilter::REASON_SHELL],
            'url'             => ['https://example.com', TermFilter::REASON_URL],
            'bare www'        => ['www.example.com', TermFilter::REASON_URL],
        ];
    }

    /**
     * Ahead of every pattern, because a control character is never a search and
     * may not even reach the patterns intact.
     */
    public function testControlCharactersAreRejectedFirst(): void
    {
        $this->assertSame(TermFilter::REASON_CONTROL, $this->filter->check("bh\x00spitze"));
        $this->assertSame(TermFilter::REASON_CONTROL, $this->filter->check("bh\x1b[0m"));
    }

    /**
     * A tab or newline is whitespace, not a control payload - somebody pasting
     * a term out of a spreadsheet should not be treated as an attacker.
     */
    public function testOrdinaryWhitespaceIsNotAControlCharacter(): void
    {
        $this->assertNull($this->filter->check("bh\tspitze"));
        $this->assertNull($this->filter->check("bh\nspitze"));
    }

    public function testOverlongInputIsRejectedByLength(): void
    {
        $this->assertNull($this->filter->check(str_repeat('a', 80)), '80 is still allowed');
        $this->assertSame(TermFilter::REASON_LENGTH, $this->filter->check(str_repeat('a', 81)));
    }

    /**
     * Length is counted in characters, not bytes: a German phrase of 60 letters
     * is well inside the limit even though its UTF-8 encoding is longer.
     */
    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $this->assertNull($this->filter->check(str_repeat('ü', 80)));
    }

    /**
     * The catch-all for payloads the patterns do not know. It only applies from
     * a few characters up, precisely so that "3/4" and "s/m" survive.
     */
    public function testMostlySymbolInputIsRejectedOnlyOnceItIsLongEnough(): void
    {
        $this->assertSame(TermFilter::REASON_SYMBOLS, $this->filter->check('!@#$%^&*()_+{}|'));
        $this->assertNull($this->filter->check('!?!'), 'too short for the ratio rule to apply');
    }

    /**
     * Both slash directions, because the character class holding them is easy
     * to break: written with a single escaped backslash the class never
     * terminates, preg_match fails to compile, and the pattern silently stops
     * matching anything at all. That is how this shipped until the suite
     * caught it.
     */
    public function testTraversalIsCaughtWithEitherSlash(): void
    {
        $this->assertSame(TermFilter::REASON_PATH, $this->filter->check('../secret'));
        $this->assertSame(TermFilter::REASON_PATH, $this->filter->check('..' . chr(92) . 'secret'));
    }

    public function testAnEmptyOrBlankTermIsNotAPayload(): void
    {
        $this->assertNull($this->filter->check(''));
        $this->assertNull($this->filter->check('   '));
    }

    public function testSurroundingWhitespaceDoesNotHideAPayload(): void
    {
        $this->assertSame(TermFilter::REASON_URL, $this->filter->check('   https://example.com   '));
    }
}
