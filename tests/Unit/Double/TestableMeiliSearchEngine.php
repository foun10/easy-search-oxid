<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Double;

use foun10\EasySearch\Engine\Meili\MeiliSearchEngine;

/**
 * MeiliSearchEngine with its one line of shop contact captured.
 *
 * An engine that cannot reach its server must never take a customer's page
 * down with it - but it must not fail silently either, so what it logs is part
 * of the behaviour and gets asserted like anything else.
 */
class TestableMeiliSearchEngine extends MeiliSearchEngine
{
    /** @var string[] */
    public array $loggedErrors = [];

    protected function logError(string $message): void
    {
        $this->loggedErrors[] = $message;
    }
}
