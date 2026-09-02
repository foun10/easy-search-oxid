<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Meili\MeiliConfiguration;

/**
 * MeiliConfiguration with the index prefix pinned.
 *
 * The real one reads the prefix from the environment first and the module
 * settings second, which is right in production and useless in a test - a
 * MEILI_INDEX_PREFIX in the shell would change what the assertions expect.
 * The naming rules below it (`_s1_l0`, the `_tmp` suffix, and what counts as a
 * shadow index) are the real ones, because the writer's behaviour depends on
 * them.
 */
class TestableMeiliConfiguration extends MeiliConfiguration
{
    public function __construct(public string $prefix = 'foun10easysearch')
    {
    }

    public function getIndexPrefix(): string
    {
        return $this->prefix;
    }
}
