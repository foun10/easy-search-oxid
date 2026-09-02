<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Core;

use foun10\EasySearch\Core\RequestQueryFactory;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Tests\Unit\Double\TestableRequestQueryFactory;
use PHPUnit\Framework\TestCase;

/**
 * The one place that turns URL parameters into a SearchQuery.
 *
 * Everything it reads is public and unauthenticated: a customer clicking
 * facets and a hand written URL arrive through the same door. So the rules
 * pinned here are as much about what the class refuses - non-IDs, negative
 * pages, oversized filter lists, parameters that arrive as arrays - as about
 * what it passes on.
 *
 * The search page, the category page and the manufacturer page all build
 * their query here, which is the point of the class: a filter, a page and a
 * price range must mean the same thing on all three. The tests therefore
 * check the two list pages against the search page rather than on their own.
 */
class RequestQueryFactoryTest extends TestCase
{
    private TestableRequestQueryFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new TestableRequestQueryFactory();
    }

    /**
     * A valid ID as the class defines one. Real ones are md5 hashes.
     */
    private function id(string $suffix): string
    {
        return md5($suffix);
    }

    // ---------------------------------------------------------------
    // fromRequest()
    // ---------------------------------------------------------------

    /**
     * An empty request is still a valid query - the search page is reachable
     * without any parameter at all.
     */
    public function testAnEmptyRequestYieldsTheDefaultQuery(): void
    {
        $query = $this->factory->fromRequest();

        $this->assertSame('', $query->getTerm());
        $this->assertSame([], $query->getFilters());
        $this->assertSame(SearchQuery::SORT_RELEVANCE, $query->getSort());
        $this->assertSame(0, $query->getOffset());
        $this->assertSame(24, $query->getLimit());
        $this->assertNull($query->getCategoryId());
        $this->assertNull($query->getManufacturerId());
        $this->assertNull($query->getPriceFrom());
        $this->assertNull($query->getPriceTo());
    }

    /**
     * The term is read unescaped on purpose: it is shown back to the customer
     * and encoded by the view, so an already escaped value would be encoded
     * twice.
     */
    public function testTheTermIsReadFromTheUnescapedParameter(): void
    {
        $this->factory->raw[RequestQueryFactory::PARAM_SEARCH] = 'Jacke & Hose';
        $this->factory->escaped[RequestQueryFactory::PARAM_SEARCH] = 'Jacke &amp; Hose';

        $this->assertSame('Jacke & Hose', $this->factory->fromRequest()->getTerm());
        $this->assertContains(RequestQueryFactory::PARAM_SEARCH, $this->factory->rawReads);
    }

    public function testShopAndLanguageComeFromTheShop(): void
    {
        $this->factory->shopId = 3;
        $this->factory->languageId = 2;

        $query = $this->factory->fromRequest();

        $this->assertSame(3, $query->getShopId());
        $this->assertSame(2, $query->getLangId());
    }

    public function testTheOffsetIsThePageTimesThePageSize(): void
    {
        $this->factory->configuredPageSize = 12;
        $this->factory->escaped[RequestQueryFactory::PARAM_PAGE] = '3';

        $query = $this->factory->fromRequest();

        $this->assertSame(36, $query->getOffset());
        $this->assertSame(12, $query->getLimit());
    }

    public function testTheSecondPageStartsAfterTheFirst(): void
    {
        $this->factory->configuredPageSize = 24;
        $this->factory->escaped[RequestQueryFactory::PARAM_PAGE] = '1';

        $this->assertSame(24, $this->factory->fromRequest()->getOffset());
    }

    /**
     * A negative page would become a negative OFFSET, which the database
     * rejects outright - so the first page is the floor.
     */
    public function testANegativePageIsClampedToTheFirstOne(): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_PAGE] = '-5';

        $this->assertSame(0, $this->factory->getPage());
        $this->assertSame(0, $this->factory->fromRequest()->getOffset());
    }

    /**
     * @dataProvider unusablePageProvider
     */
    public function testAnUnusablePageParameterIsTheFirstOne(mixed $value): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_PAGE] = $value;

        $this->assertSame(0, $this->factory->getPage());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public function unusablePageProvider(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'not a number' => ['zwei'],
            'an array' => [['2']],
        ];
    }

    /**
     * PHP hands parameters over as strings, but nothing in the chain
     * guarantees it - a page that arrives as an int must still page.
     */
    public function testAPageThatArrivesAsAnIntegerIsUsed(): void
    {
        $this->factory->configuredPageSize = 10;
        $this->factory->escaped[RequestQueryFactory::PARAM_PAGE] = 2;

        $this->assertSame(2, $this->factory->getPage());
        $this->assertSame(20, $this->factory->fromRequest()->getOffset());
    }

    /**
     * @dataProvider unusablePageSizeProvider
     */
    public function testAnUnusablePageSizeFallsBackToTheDefault(int $configured): void
    {
        $this->factory->configuredPageSize = $configured;

        $this->assertSame(24, $this->factory->getPageSize());
        $this->assertSame(24, $this->factory->fromRequest()->getLimit());
    }

    /**
     * @return array<string, array{int}>
     */
    public function unusablePageSizeProvider(): array
    {
        return [
            'setting missing' => [0],
            'setting negative' => [-10],
        ];
    }

    public function testTheConfiguredPageSizeWins(): void
    {
        $this->factory->configuredPageSize = 48;

        $this->assertSame(48, $this->factory->getPageSize());
    }

    public function testCategoryAndManufacturerAreReadFromTheSearchParameters(): void
    {
        $this->factory->escaped['searchcnid'] = $this->id('category');
        $this->factory->escaped['searchmanufacturer'] = $this->id('manufacturer');

        $query = $this->factory->fromRequest();

        $this->assertSame($this->id('category'), $query->getCategoryId());
        $this->assertSame($this->id('manufacturer'), $query->getManufacturerId());
    }

    public function testAnInvalidCategoryOrManufacturerIsDropped(): void
    {
        $this->factory->escaped['searchcnid'] = 'a category, but not an id';
        $this->factory->escaped['searchmanufacturer'] = '';

        $query = $this->factory->fromRequest();

        $this->assertNull($query->getCategoryId());
        $this->assertNull($query->getManufacturerId());
    }

    public function testThePriceRangeIsReadAsFloats(): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_PRICE_FROM] = '19.99';
        $this->factory->escaped[RequestQueryFactory::PARAM_PRICE_TO] = '250';

        $query = $this->factory->fromRequest();

        $this->assertSame(19.99, $query->getPriceFrom());
        $this->assertSame(250.0, $query->getPriceTo());
    }

    public function testTheSortFragmentReachesTheQuery(): void
    {
        $this->assertSame(
            SearchQuery::SORT_PRICE_DESC,
            $this->factory->fromRequest('oxprice desc')->getSort()
        );
    }

    public function testTheFiltersReachTheQuery(): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = [
            $this->id('color') => [$this->id('red')],
        ];

        $filters = $this->factory->fromRequest()->getFilters();

        $this->assertCount(1, $filters);
        $this->assertSame($this->id('color'), $filters[0]->getAttributeId());
    }

    /**
     * searchparam[]=x is a request anyone can send. A plain string cast would
     * warn and then search for the literal "Array".
     */
    public function testATermThatArrivesAsAnArrayIsNotSearchedFor(): void
    {
        $this->factory->raw[RequestQueryFactory::PARAM_SEARCH] = ['Jacke'];

        $this->assertSame('', $this->factory->fromRequest()->getTerm());
    }

    // ---------------------------------------------------------------
    // forCategory() / forManufacturer()
    // ---------------------------------------------------------------

    /**
     * A category page is narrowed by its category and its facets, never by a
     * search term - so the term stays empty even when one is in the URL, and
     * the category comes from the caller rather than from searchcnid.
     */
    public function testForCategoryIgnoresTheSearchParameters(): void
    {
        $this->factory->raw[RequestQueryFactory::PARAM_SEARCH] = 'Jacke';
        $this->factory->escaped['searchcnid'] = $this->id('other category');
        $this->factory->escaped['searchmanufacturer'] = $this->id('manufacturer');

        $query = $this->factory->forCategory($this->id('category'));

        $this->assertSame('', $query->getTerm());
        $this->assertSame($this->id('category'), $query->getCategoryId());
        $this->assertNull($query->getManufacturerId());
    }

    public function testForManufacturerIgnoresTheSearchParameters(): void
    {
        $this->factory->raw[RequestQueryFactory::PARAM_SEARCH] = 'Jacke';
        $this->factory->escaped['searchcnid'] = $this->id('category');
        $this->factory->escaped['searchmanufacturer'] = $this->id('other manufacturer');

        $query = $this->factory->forManufacturer($this->id('manufacturer'));

        $this->assertSame('', $query->getTerm());
        $this->assertSame($this->id('manufacturer'), $query->getManufacturerId());
        $this->assertNull($query->getCategoryId());
    }

    /**
     * The whole point of the class: what a filter, a page and a price range
     * mean must not depend on which of the three pages is being rendered.
     */
    public function testTheListPagesReadFiltersPagingAndPricesLikeTheSearchPage(): void
    {
        $this->factory->configuredPageSize = 10;
        $this->factory->escaped[RequestQueryFactory::PARAM_PAGE] = '2';
        $this->factory->escaped[RequestQueryFactory::PARAM_PRICE_FROM] = '5';
        $this->factory->escaped[RequestQueryFactory::PARAM_PRICE_TO] = '50';
        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = [
            $this->id('size') => [$this->id('40')],
        ];

        $queries = [
            'search' => $this->factory->fromRequest('oxtitle asc'),
            'category' => $this->factory->forCategory($this->id('category'), 'oxtitle asc'),
            'manufacturer' => $this->factory->forManufacturer($this->id('manufacturer'), 'oxtitle asc'),
        ];

        foreach ($queries as $page => $query) {
            $this->assertSame(20, $query->getOffset(), $page);
            $this->assertSame(10, $query->getLimit(), $page);
            $this->assertSame(5.0, $query->getPriceFrom(), $page);
            $this->assertSame(50.0, $query->getPriceTo(), $page);
            $this->assertSame(SearchQuery::SORT_TITLE_ASC, $query->getSort(), $page);
            $this->assertCount(1, $query->getFilters(), $page);
            $this->assertSame([$this->id('40')], $query->getFilters()[0]->getValueIds(), $page);
        }
    }

    /**
     * The listing ID is the caller's, not the request's, so it is passed on as
     * given - a page for an ID the shop cannot resolve is the caller's
     * problem, and silently listing everything instead would be worse than an
     * empty result.
     */
    public function testTheListingIdIsPassedOnAsGiven(): void
    {
        $this->assertSame(
            'not an id at all',
            $this->factory->forCategory('not an id at all')->getCategoryId()
        );
    }

    // ---------------------------------------------------------------
    // getFilters()
    // ---------------------------------------------------------------

    public function testEachAttributeBecomesOneFilter(): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = [
            $this->id('color') => [$this->id('red'), $this->id('blue')],
            $this->id('size') => [$this->id('40')],
        ];

        $filters = $this->factory->getFilters();

        $this->assertCount(2, $filters);
        $this->assertSame($this->id('color'), $filters[0]->getAttributeId());
        $this->assertSame([$this->id('red'), $this->id('blue')], $filters[0]->getValueIds());
        $this->assertSame($this->id('size'), $filters[1]->getAttributeId());
        $this->assertSame([$this->id('40')], $filters[1]->getValueIds());
    }

    /**
     * A hand shortened URL that drops the [] should still filter.
     */
    public function testABareValueIsTakenAsAListOfOne(): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = [
            $this->id('color') => $this->id('red'),
        ];

        $this->assertSame([$this->id('red')], $this->factory->getFilters()[0]->getValueIds());
    }

    public function testRepeatedValuesAreCollapsedAndRenumbered(): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = [
            $this->id('color') => [$this->id('red'), $this->id('red'), $this->id('blue')],
        ];

        $this->assertSame(
            [$this->id('red'), $this->id('blue')],
            $this->factory->getFilters()[0]->getValueIds()
        );
    }

    public function testValuesThatAreNotIdsAreDropped(): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = [
            $this->id('color') => ['rot; DROP TABLE', $this->id('red'), '', $this->id('blue')],
        ];

        $this->assertSame(
            [$this->id('red'), $this->id('blue')],
            $this->factory->getFilters()[0]->getValueIds()
        );
    }

    /**
     * A numeric attribute ID arrives as an int, because PHP turns numeric
     * array keys into ints. The ID rule takes strings, so a missing cast
     * would be a TypeError in the render path rather than a filter.
     */
    public function testANumericAttributeIdStillFilters(): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = [
            '4711' => [$this->id('red')],
        ];

        $filters = $this->factory->getFilters();

        $this->assertCount(1, $filters);
        $this->assertSame('4711', $filters[0]->getAttributeId());
    }

    /**
     * An attribute whose values were all rejected carries no selection, so it
     * must not become an empty filter - the engines read an empty value list
     * as "no restriction".
     */
    public function testAnAttributeWithoutUsableValuesIsSkipped(): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = [
            $this->id('color') => ['not an id'],
            $this->id('size') => [$this->id('40')],
        ];

        $filters = $this->factory->getFilters();

        $this->assertCount(1, $filters);
        $this->assertSame($this->id('size'), $filters[0]->getAttributeId());
    }

    public function testAnAttributeThatIsNotAnIdIsSkipped(): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = [
            'colour or something' => [$this->id('red')],
        ];

        $this->assertSame([], $this->factory->getFilters());
    }

    /**
     * foun10filter[a][b][]=c hands the value mapper an array. Dropping it
     * beats a warning in the render path plus a filter on "Array".
     */
    public function testNestedFilterValuesAreDropped(): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = [
            $this->id('color') => [[$this->id('red')], $this->id('blue')],
        ];

        $this->assertSame([$this->id('blue')], $this->factory->getFilters()[0]->getValueIds());
    }

    /**
     * @dataProvider unusableFilterParameterProvider
     */
    public function testAFilterParameterThatIsNotAUsableArrayYieldsNoFilters(mixed $value): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = $value;

        $this->assertSame([], $this->factory->getFilters());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public function unusableFilterParameterProvider(): array
    {
        return [
            'missing' => [null],
            'a bare string' => ['d1e0e3c7a1c0a6e6f2f4b5c6d7e8f9a0'],
            'empty array' => [[]],
        ];
    }

    /**
     * The caps exist because the parameter is public: without them a hand
     * written URL decides how large an IN list the database is handed and how
     * many EXISTS subqueries one request runs.
     */
    public function testAtMostFiftyValuesPerAttributeSurvive(): void
    {
        $values = [];

        for ($i = 0; $i < 60; $i++) {
            $values[] = $this->id('value ' . $i);
        }

        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = [
            $this->id('color') => $values,
        ];

        $kept = $this->factory->getFilters()[0]->getValueIds();

        $this->assertCount(50, $kept);
        $this->assertSame(array_slice($values, 0, 50), $kept);
    }

    public function testAtMostTwentyAttributesSurvive(): void
    {
        $raw = [];

        for ($i = 0; $i < 25; $i++) {
            $raw[$this->id('attribute ' . $i)] = [$this->id('value ' . $i)];
        }

        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = $raw;

        $filters = $this->factory->getFilters();

        $this->assertCount(20, $filters);
        $this->assertSame($this->id('attribute 0'), $filters[0]->getAttributeId());
        $this->assertSame($this->id('attribute 19'), $filters[19]->getAttributeId());
    }

    /**
     * The cap counts what survived, not what arrived: twenty usable
     * attributes still come through when unusable ones are mixed in.
     */
    public function testTheAttributeCapCountsOnlyUsableAttributes(): void
    {
        $raw = ['not an id' => [$this->id('red')]];

        for ($i = 0; $i < 20; $i++) {
            $raw[$this->id('attribute ' . $i)] = [$this->id('value ' . $i)];
        }

        $this->factory->escaped[RequestQueryFactory::PARAM_FILTER] = $raw;

        $this->assertCount(20, $this->factory->getFilters());
    }

    // ---------------------------------------------------------------
    // mapSort()
    // ---------------------------------------------------------------

    /**
     * @dataProvider sortProvider
     */
    public function testTheSortFragmentIsTranslatedToAnEngineConstant(?string $sortSql, string $expected): void
    {
        $this->assertSame($expected, $this->factory->mapSort($sortSql));
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public function sortProvider(): array
    {
        return [
            'nothing sorted' => [null, SearchQuery::SORT_RELEVANCE],
            'empty fragment' => ['', SearchQuery::SORT_RELEVANCE],
            'blank fragment' => ['   ', SearchQuery::SORT_RELEVANCE],
            'unknown column' => ['oxbrand asc', SearchQuery::SORT_RELEVANCE],
            'price ascending' => ['oxprice asc', SearchQuery::SORT_PRICE_ASC],
            'price descending' => ['oxprice desc', SearchQuery::SORT_PRICE_DESC],
            'price uppercase' => ['OXPRICE DESC', SearchQuery::SORT_PRICE_DESC],
            'title' => ['oxtitle asc', SearchQuery::SORT_TITLE_ASC],
            'newest' => ['oxinsert desc', SearchQuery::SORT_NEWEST],
            'bestseller' => ['oxsold desc', SearchQuery::SORT_BESTSELLER],
        ];
    }

    /**
     * The engine groups variants and sorts by an aggregate, so it offers no
     * descending title order. An "oxtitle desc" from the shop's sort box
     * lands on the ascending one rather than on relevance.
     */
    public function testOnlyThePriceSortHasADescendingVariant(): void
    {
        $this->assertSame(SearchQuery::SORT_TITLE_ASC, $this->factory->mapSort('oxtitle desc'));
        $this->assertSame(SearchQuery::SORT_NEWEST, $this->factory->mapSort('oxinsert asc'));
        $this->assertSame(SearchQuery::SORT_BESTSELLER, $this->factory->mapSort('oxsold asc'));
    }

    /**
     * OXID hands over more than one column for some sortings; price decides.
     */
    public function testPriceWinsOverTheOtherColumns(): void
    {
        $this->assertSame(SearchQuery::SORT_PRICE_ASC, $this->factory->mapSort('oxprice asc, oxtitle asc'));
    }

    // ---------------------------------------------------------------
    // the parameter rules themselves
    // ---------------------------------------------------------------

    public function testAnEncodedParameterIsDecodedBeforeItIsChecked(): void
    {
        $this->factory->escaped['searchcnid'] = 'a%2Db';

        $this->assertSame('a-b', $this->factory->getStringOrNullPublic('searchcnid'));
    }

    /**
     * Decoding first is what makes the check worth having: %2E%2E%2F passes
     * the pattern while encoded and is a path traversal once decoded.
     */
    public function testAParameterThatIsOnlyValidWhileEncodedIsRejected(): void
    {
        $this->factory->escaped['searchcnid'] = '%2E%2E%2Fetc';

        $this->assertNull($this->factory->getStringOrNullPublic('searchcnid'));
    }

    public function testAParameterThatArrivesAsAnArrayIsRejected(): void
    {
        $this->factory->escaped['searchcnid'] = [md5('category')];

        $this->assertNull($this->factory->getStringOrNullPublic('searchcnid'));
    }

    /**
     * @dataProvider floatProvider
     */
    public function testOnlyNumericParametersBecomeAPrice(mixed $value, ?float $expected): void
    {
        $this->factory->escaped[RequestQueryFactory::PARAM_PRICE_FROM] = $value;

        $this->assertSame(
            $expected,
            $this->factory->getFloatOrNullPublic(RequestQueryFactory::PARAM_PRICE_FROM)
        );
    }

    /**
     * @return array<string, array{mixed, ?float}>
     */
    public function floatProvider(): array
    {
        return [
            'decimal' => ['19.99', 19.99],
            'integer string' => ['250', 250.0],
            'a real int' => [250, 250.0],
            'zero' => ['0', 0.0],
            'negative' => ['-5', -5.0],
            'missing' => [null, null],
            'empty' => ['', null],
            'words' => ['billig', null],
            'an array' => [['19.99'], null],
        ];
    }

    /**
     * @dataProvider idProvider
     */
    public function testWhatCountsAsAnId(string $value, bool $expected): void
    {
        $this->assertSame($expected, $this->factory->isIdPublic($value));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public function idProvider(): array
    {
        return [
            'an md5 hash' => ['5f9c4ab08cac7457e9111a30e4664920', true],
            'letters, digits, dot, dash, underscore' => ['a1_b.c-d', true],
            'sixty four characters' => [str_repeat('a', 64), true],
            'sixty five characters' => [str_repeat('a', 65), false],
            'empty' => ['', false],
            'with a space' => ['a b', false],
            'with a slash' => ['a/b', false],
            'with a quote' => ['a\'b', false],
            'with a percent' => ['a%b', false],
        ];
    }
}
