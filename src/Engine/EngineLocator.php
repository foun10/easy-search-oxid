<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine;

use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Engine\Meili\MeiliSearchEngine;
use foun10\EasySearch\Engine\MySql\MySqlSearchEngine;
use InvalidArgumentException;

/**
 * Hands out a search engine by name.
 *
 * Which one a shop uses is a module setting, so the four subshops can run
 * different backends from the same code base - and a single one can be moved to
 * Meilisearch while the others stay on MySQL, which is what makes the switch
 * reversible in the admin instead of in a deployment.
 *
 * Being able to ask for an engine by name is also what the benchmark command
 * needs: it runs the same query through both and compares.
 */
class EngineLocator
{
    public function __construct(
        protected ModuleSettings $moduleSettings,
        protected MySqlSearchEngine $mySqlSearchEngine,
        protected MeiliSearchEngine $meiliSearchEngine,
        protected NullSearchEngine $nullSearchEngine
    ) {
    }

    /**
     * The engine this shop is configured for.
     */
    public function getConfigured(): SearchEngineInterface
    {
        return $this->get($this->moduleSettings->getEngine());
    }

    public function get(string $name): SearchEngineInterface
    {
        switch ($name) {
            case ModuleSettings::ENGINE_MYSQL:
                return $this->mySqlSearchEngine;

            case ModuleSettings::ENGINE_MEILISEARCH:
                return $this->meiliSearchEngine;

            case ModuleSettings::ENGINE_NULL:
                return $this->nullSearchEngine;
        }

        throw new InvalidArgumentException(sprintf('Unknown search engine "%s"', $name));
    }

    /**
     * Engines that actually answer, in the order a comparison should print
     * them. The null engine is left out - there is nothing to compare about it.
     *
     * @return string[]
     */
    public function getComparableNames(): array
    {
        return [ModuleSettings::ENGINE_MYSQL, ModuleSettings::ENGINE_MEILISEARCH];
    }
}
