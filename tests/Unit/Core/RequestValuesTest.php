<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Core;

use foun10\EasySearch\Core\RequestValues;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The rule every controller reads its parameters through.
 *
 * Worth pinning on its own rather than only through its users, because it is
 * the module's single answer to a class of bug that is quiet in both
 * directions. `?langId[]=1` casts to the integer 1 with no warning at all, so
 * a rebuild runs against a language nobody asked for; `(string)` on the same
 * value warns in the render path and then works with the literal "Array".
 *
 * Note what it deliberately does not do: it does not validate. A value that is
 * a scalar is converted and handed on, because every caller already clamps,
 * matches or rejects what it gets. The only judgement here is about shape.
 */
class RequestValuesTest extends TestCase
{
    private object $values;

    protected function setUp(): void
    {
        $this->values = new class () {
            use RequestValues;

            public function string(mixed $value): string
            {
                return $this->toString($value);
            }

            public function int(mixed $value): int
            {
                return $this->toInt($value);
            }
        };
    }

    /**
     * @dataProvider scalarProvider
     */
    public function testAScalarIsConvertedRatherThanRejected(mixed $value, string $string, int $int): void
    {
        $this->assertSame($string, $this->values->string($value));
        $this->assertSame($int, $this->values->int($value));
    }

    public function scalarProvider(): array
    {
        return [
            // Everything in a query string arrives as one of these.
            'a string'          => ['500', '500', 500],
            'an empty string'   => ['', '', 0],
            'a word'            => ['clear', 'clear', 0],
            'a number with tail' => ['750abc', '750abc', 750],
            'an int'            => [42, '42', 42],
            'zero'              => [0, '0', 0],
            'a negative'        => [-7, '-7', -7],
            'a float'           => [1.9, '1.9', 1],
            'true'              => [true, '1', 1],
            'false'             => [false, '', 0],
        ];
    }

    /**
     * The case the whole trait exists for: any parameter can arrive as an
     * array, and no caller in this module has a use for one.
     *
     * @dataProvider nonScalarProvider
     */
    public function testAnythingThatIsNotAScalarBecomesNothing(mixed $value): void
    {
        $this->assertSame('', $this->values->string($value));
        $this->assertSame(0, $this->values->int($value));
    }

    public function nonScalarProvider(): array
    {
        return [
            'a list'          => [['1']],
            'a nested array'  => [[['a' => 'b']]],
            'an empty array'  => [[]],
            'null'            => [null],
            'an object'       => [new stdClass()],
        ];
    }

    /**
     * An array used to become the integer 1 silently - not the empty string,
     * not a warning, just the wrong language or the wrong page. Pinned as its
     * own case because it is the one that cost the debugging.
     */
    public function testAnArrayIsNotSilentlyTheNumberOne(): void
    {
        $this->assertNotSame(1, $this->values->int(['anything']));
        $this->assertSame(0, $this->values->int(['anything']));
    }
}
