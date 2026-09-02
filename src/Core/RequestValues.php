<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

/**
 * Request parameters read as the type they are wanted in, without trusting
 * them to be it already.
 *
 * Every parameter a controller reads is attacker-controlled in shape as well
 * as in content, and any of them can arrive as an array: `langId[]=1`,
 * `foun10filter[a][b][]=c`. What a plain cast then does is quiet and wrong in
 * two different ways:
 *
 *     (string) ['x']   // "Array to string conversion" warning, then "Array"
 *     (int) ['x']      // no warning at all, and the value is 1
 *
 * The string case costs a warning in the render path - a red test under the
 * suite's failOnWarning - in exchange for a value nobody meant. The int case
 * is worse for being silent: `?langId[]=x` becomes language 1, and a batch
 * size, a page number or a shop ID goes the same way.
 *
 * So the check belongs before the cast, not after it. Non-scalars become the
 * empty string and zero, which every caller in this module already rejects or
 * clamps.
 */
trait RequestValues
{
    protected function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    protected function toInt(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }
}
