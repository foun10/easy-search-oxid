<?php

declare(strict_types=1);

namespace OxidEsales\Eshop\Application\Controller\Admin;

/**
 * Stand-in for OXID's admin controller base class.
 *
 * Deliberately almost empty, and that is the finding rather than a shortcut:
 * the module's four admin controllers use **nothing** from their parent except
 * the template property. Every `$this->...()` call in them resolves to a method
 * they define themselves or get from the ReindexPhases trait, and both
 * getEditShopId() and getEditLanguageId() - the two that sound inherited - are
 * their own, because what counts as "the shop being edited" is a decision each
 * screen makes.
 *
 * So there is nothing here for a test to drift away from. If a controller ever
 * does start calling into the real base class, it will fail loudly here rather
 * than pass against a fake, which is the property that makes this stub safe.
 *
 * Loaded from the bootstrap only when the real class cannot be resolved - see
 * the note there about why the guards autoload.
 */
class AdminController
{
    /**
     * The template a screen renders. Read by OXID, written by the module, and
     * touched by nothing in between.
     *
     * @var string
     */
    protected $_sThisTemplate = '';
}
