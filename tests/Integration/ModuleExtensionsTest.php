<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Verifies that the module is actually wired into the running shop.
 *
 * None of this is reachable from a unit test, and that is the point rather than a
 * limitation: the `*_parent` classes exist only once OXID has built the extension chain
 * for an activated module, so the whole question "does this override anything" cannot be
 * asked without a shop.
 *
 * The failure mode these guard against produces no error at all. An override whose name no
 * longer matches the parent's is simply never called - no exception, no log line, the
 * feature just stops. OXID 7 removed the underscore prefix from protected methods, which
 * turned a whole generation of overrides into dead code overnight, and a renamed or
 * re-signatured shop method does the same thing at any release.
 */
class ModuleExtensionsTest extends TestCase
{
    /**
     * Methods the module adds rather than overrides carry its own prefix.
     *
     * Deriving the guard from that convention rather than from a hand-kept list means
     * there is no list to forget to update - and it enforces the convention at the same
     * time. Both spellings are in use: `foun10GetEngine()` for internals, and
     * `getFoun10SuggestUrl()` for the ones a template calls, where the getter has to read
     * as one.
     */
    private const OWN_METHOD_PATTERN = '/foun10/i';

    /**
     * @dataProvider extendedClassProvider
     */
    public function testShopClassIsExtendedByTheModule(string $shopClass, string $moduleClass): void
    {
        $instance = oxNew($shopClass);

        $this->assertInstanceOf(
            $moduleClass,
            $instance,
            $shopClass . ' is not extended - check the extend map in metadata.php'
        );
    }

    /**
     * Every extended class in metadata.php, so a new entry is covered without anybody
     * remembering to add it here.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function extendedClassProvider(): array
    {
        $metadata = $this->readMetadata();
        $cases = [];

        foreach ($metadata['extend'] as $shopClass => $moduleClass) {
            $cases[$shopClass] = [$shopClass, $moduleClass];
        }

        return $cases;
    }

    /**
     * The override guard, derived by reflection rather than from a list.
     *
     * Every method a module extension declares is either an override - it hooks into the
     * shop by replacing a parent method - or something the module added itself. The
     * dangerous case is a method that *was* an override and quietly stopped being one,
     * because the shop then never calls it.
     *
     * @dataProvider extensionClassProvider
     */
    public function testEveryMethodEitherOverridesSomethingOrIsMarkedAsTheModulesOwn(
        string $moduleClass
    ): void {
        $parent = get_parent_class($moduleClass);

        $this->assertNotFalse(
            $parent,
            $moduleClass . ' has no parent - is the module activated in this shop?'
        );

        $unexpected = [];

        foreach ($this->declaredMethods($moduleClass) as $method) {
            if (method_exists($parent, $method)) {
                continue;
            }

            if (preg_match(self::OWN_METHOD_PATTERN, $method) === 1) {
                continue;
            }

            $unexpected[] = $method;
        }

        $this->assertSame(
            [],
            $unexpected,
            sprintf(
                "%s declares method(s) that override nothing in %s: %s\n"
                . 'Either the shop renamed them - in which case the hook is dead and the '
                . 'feature has silently stopped working - or they are the module\'s own and '
                . 'their names are missing the foun10 prefix.',
                $moduleClass,
                $parent,
                implode(', ', $unexpected)
            )
        );
    }

    /**
     * An override has to be callable the way the shop calls it.
     *
     * A parameter added without a default, or a return type narrowed, makes PHP fatal at
     * class-load time rather than silently - but only for the classes actually loaded in a
     * request. Checking every one here means a listing page nobody visited during a test
     * cannot be the place it first shows up.
     *
     * @dataProvider extensionClassProvider
     */
    public function testEveryOverrideStillMatchesTheSignatureTheShopCallsItWith(
        string $moduleClass
    ): void {
        $parent = get_parent_class($moduleClass);
        $mismatched = [];

        foreach ($this->declaredMethods($moduleClass) as $method) {
            if (!method_exists($parent, $method)) {
                continue;
            }

            $own = new ReflectionMethod($moduleClass, $method);
            $inherited = new ReflectionMethod($parent, $method);

            if ($own->getNumberOfRequiredParameters() > $inherited->getNumberOfParameters()) {
                $mismatched[] = $method;
            }
        }

        $this->assertSame([], $mismatched, sprintf(
            '%s overrides %s with more required parameters than the shop passes.',
            $moduleClass,
            implode(', ', $mismatched)
        ));
    }

    /**
     * Every method the shipped templates call actually runs.
     *
     * The guards above check *names*: that a method overrides something, or is marked as the
     * module's own. Neither looks inside a body, and that is exactly where renaming
     * `getFacets()` to `getFoun10Facets()` left one caller behind - `getFoun10ActiveFilterCount()`
     * still asked for the old name. Every name was right, both guards passed, the unit suite
     * passed, and the filter panel died on render with "Function 'getFacets' does not exist".
     *
     * So this calls them. Only the no-argument ones, which is all the templates use without a
     * facet in hand, and only for what they must not do: throw.
     *
     * @dataProvider facetPresentationMethodProvider
     */
    public function testEveryMethodTheTemplatesCallCanActuallyBeCalled(string $method): void
    {
        $controller = oxNew(\OxidEsales\Eshop\Application\Controller\SearchController::class);

        if (!method_exists($controller, $method)) {
            $this->markTestSkipped($method . ' is not on the search controller - is the module activated?');
        }

        try {
            $controller->$method();
        } catch (\Throwable $exception) {
            $this->fail(sprintf(
                "%s() threw %s: %s\nA template calling it renders nothing and logs a stack trace.",
                $method,
                get_class($exception),
                $exception->getMessage()
            ));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The no-argument methods views/twig/frontend/* calls on the controller.
     *
     * @return array<string, array{0: string}>
     */
    public function facetPresentationMethodProvider(): array
    {
        $methods = [
            // FacetPresentation, used by the panel, the toolbar and the chips
            'getFoun10Facets',
            'hasFoun10Facets',
            'hasFoun10ActiveFilters',
            'getFoun10ActiveFilterCount',
            'getFoun10FilterResetUrl',
            'getFoun10FacetContext',
            // SearchController's own, used by the correction line
            'getFoun10SearchCorrection',
            'getFoun10EffectiveSearchParam',
            'getFoun10UncorrectedSearchUrl',
        ];

        $cases = [];

        foreach ($methods as $method) {
            $cases[$method] = [$method];
        }

        return $cases;
    }

    /**
     * The extension classes, found on disk rather than listed - the same reason as above.
     *
     * @return array<string, array{0: string}>
     */
    public function extensionClassProvider(): array
    {
        $cases = [];

        foreach (array_values($this->readMetadata()['extend']) as $moduleClass) {
            $cases[$moduleClass] = [$moduleClass];
        }

        return $cases;
    }

    /**
     * Method names the class itself declares, trait methods included.
     *
     * The trait matters here: three of the five extensions get their facet handling from
     * FacetPresentation, and a trait method lands in the class as if it had been written
     * there - so it is just as capable of overriding a parent method, or of failing to.
     *
     * @return string[]
     */
    private function declaredMethods(string $moduleClass): array
    {
        $methods = [];

        foreach ((new ReflectionClass($moduleClass))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $moduleClass) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    /**
     * @return array<string, mixed>
     */
    private function readMetadata(): array
    {
        $aModule = [];

        require __DIR__ . '/../../metadata.php';

        return $aModule;
    }
}
