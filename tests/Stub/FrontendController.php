<?php

declare(strict_types=1);

namespace OxidEsales\Eshop\Application\Controller;

/**
 * Stand-in for OXID's frontend controller base class.
 *
 * Empty for the same reason tests/Stub/AdminController.php is: the module's two
 * own frontend controllers - the facet and suggest endpoints - use nothing from
 * their parent. Both answer JSON and end the request, so they never reach a
 * template, never call parent::render(), and every `$this->...()` in them is
 * their own.
 *
 * Note which classes this does **not** cover. The three classes under
 * src/Extension are OXID module extensions and extend `*_parent`, a class the
 * unified namespace generator creates when the module is activated - it exists
 * only in a live installation. They also genuinely call into it
 * (parent::loadArticles(), parent::getBaseLink(), $this->getSortingSql()), and
 * standing in for those would mean inventing the shop's behaviour and then
 * testing the invention. Those belong to the integration suite.
 *
 * Loaded from the bootstrap only when the real class cannot be resolved.
 */
class FrontendController
{
    /**
     * @var string
     */
    protected $_sThisTemplate = '';
}
