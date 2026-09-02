<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index;

use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Index\Meili\MeiliIndexWriter;
use foun10\EasySearch\Index\MySql\MySqlIndexWriter;
use InvalidArgumentException;

/**
 * Hands out an index writer by engine name.
 *
 * The counterpart of EngineLocator on the write side. Two reasons it exists:
 * the reindex command can be told which backend to fill (--engine), which is
 * what makes it possible to keep both indexes current and compare them; and
 * everything else can keep asking for whatever this shop is configured for.
 */
class IndexWriterLocator
{
    public function __construct(
        protected ModuleSettings $moduleSettings,
        protected MySqlIndexWriter $mySqlIndexWriter,
        protected MeiliIndexWriter $meiliIndexWriter
    ) {
    }

    public function getConfigured(): IndexWriterInterface
    {
        $engine = $this->moduleSettings->getEngine();

        // "null" means the shop serves its own search, but an index still has
        // to be written somewhere - and MySQL is the one that is always there.
        return $engine === ModuleSettings::ENGINE_MEILISEARCH
            ? $this->meiliIndexWriter
            : $this->mySqlIndexWriter;
    }

    public function get(string $name): IndexWriterInterface
    {
        switch ($name) {
            case ModuleSettings::ENGINE_MYSQL:
                return $this->mySqlIndexWriter;

            case ModuleSettings::ENGINE_MEILISEARCH:
                return $this->meiliIndexWriter;
        }

        throw new InvalidArgumentException(sprintf('Unknown index writer "%s"', $name));
    }

    /**
     * @return string[]
     */
    public function getNames(): array
    {
        return [ModuleSettings::ENGINE_MYSQL, ModuleSettings::ENGINE_MEILISEARCH];
    }
}
