<?php

namespace App\Tests\Unit\Service;

use App\Client\AlteredCoreClient;
use App\Entity\User;
use App\Repository\CollectionCardViewRepository;
use App\Service\CollectionPlaysetCardsService;
use PHPUnit\Framework\TestCase;

class CollectionPlaysetCardsServiceTest extends TestCase
{
    private CollectionCardViewRepository   $viewRepository;
    private AlteredCoreClient              $client;
    private CollectionPlaysetCardsService  $service;
    private User                           $user;

    protected function setUp(): void
    {
        $this->viewRepository = $this->createMock(CollectionCardViewRepository::class);
        $this->client         = $this->createMock(AlteredCoreClient::class);
        $this->user           = $this->createMock(User::class);

        $this->service = new CollectionPlaysetCardsService($this->viewRepository, $this->client);
    }

    // ── Builders for the trimmed universe shape returned by fetchPlaysetUniverse ──────────────

    /**
     * One universe version (the trimmed shape AlteredCoreClient::fetchPlaysetUniverse returns).
     * name/imagePath are multilingual objects, like the real cards API.
     */
    private function version(
        string $reference,
        string $faction,
        string $rarity,
        bool   $transfuge,
        string $cardType = 'CHARACTER',
        string $setReference = 'DUSTER',
        string $nameEn = 'Ira, Fair Attendee',
        ?string $collector = null,
    ): array {
        return [
            'reference'                 => $reference,
            'collectorNumberFormatedId' => $collector ?? ($reference . '-CN'),
            'transfuge'                 => $transfuge,
            'set'                       => ['reference' => $setReference, 'name' => 'Seeds of Unity', 'code' => 'SDU'],
            'faction'                   => ['code' => $faction],
            'rarity'                    => ['reference' => $rarity],
            'cardType'                  => ['reference' => $cardType],
            'name'                      => ['en' => $nameEn, 'fr' => $nameEn . ' (fr)'],
            'imagePath'                 => ['en' => $reference . '-en.jpg', 'fr' => $reference . '-fr.jpg'],
        ];
    }

    /** The standard 3-version Axiom card whose R2 is a Bravos transfuge (the spec example shape). */
    private function transfugeCardVersions(string $base = 'ALT_DUSTER_B_AX_88'): array
    {
        return [
            $this->version($base . '_C',  'AX', 'COMMON', false),
            $this->version($base . '_R1', 'AX', 'RARE',   false),
            $this->version($base . '_R2', 'BR', 'RARE',   true),
        ];
    }

    private function mockUniverse(array $versions): void
    {
        $this->client->method('fetchPlaysetUniverse')->willReturn($versions);
    }

    /** @param array<string,int> $owned reference => quantity (one row each; the service sums) */
    private function mockOwned(array $owned): void
    {
        $rows = [];
        foreach ($owned as $ref => $qty) {
            $rows[] = ['faction' => 'AX', 'cardSet' => 'DUSTER', 'cardReference' => $ref, 'quantity' => $qty];
        }
        $this->viewRepository->method('findOwnedCardQuantities')->willReturn($rows);
    }

    private function findCard(array $items, string $baseReference): array
    {
        foreach ($items as $item) {
            if ($item['baseReference'] === $baseReference) {
                return $item;
            }
        }
        $this->fail("Card {$baseReference} not found in items.");
    }

    // ── Tests ─────────────────────────────────────────────────────────────────────────────────

    public function testListsTheWholeUniverseIncludingUnownedCards(): void
    {
        $this->mockUniverse($this->transfugeCardVersions());
        $this->mockOwned([]); // user owns nothing

        $result = $this->service->listCards($this->user);

        $this->assertSame(1, $result['totalItems']);
        $card = $result['items'][0];
        $this->assertSame('ALT_DUSTER_B_AX_88', $card['baseReference']);
        $this->assertCount(3, $card['versions']);
        foreach ($card['versions'] as $v) {
            $this->assertSame(0, $v['owned']); // owned=0 versions are present
        }
    }

    public function testReturnsTheEnvelopeShape(): void
    {
        $this->mockUniverse($this->transfugeCardVersions());
        $this->mockOwned([]);

        $result = $this->service->listCards($this->user);

        $this->assertSame(['summary', 'items', 'page', 'itemsPerPage', 'totalItems', 'totalPages'], array_keys($result));
        $this->assertSame(['totalCards', 'totalVersions', 'totalOwned', 'ownedBuckets'], array_keys($result['summary']));
        // Numeric-string keys ('0','3') are coerced to int array keys by PHP; json_encode still
        // renders them as the string object keys "0"/"3". Compare as strings.
        $this->assertSame(['0', '1-2', '3', '4plus'], array_map('strval', array_keys($result['summary']['ownedBuckets'])));
        $card = $result['items'][0];
        $this->assertSame(['baseReference', 'name', 'cardSet', 'cardType', 'versions'], array_keys($card));
        $version = $card['versions'][0];
        $this->assertSame(
            ['reference', 'collectorNumberFormatedId', 'faction', 'rarity', 'transfuge', 'owned', 'imagePath'],
            array_keys($version),
        );
    }

    public function testOrdersVersionsCommonRare1Rare2Exalted(): void
    {
        // Provide them out of order; expect C, R1, R2 ordering.
        $this->mockUniverse([
            $this->version('ALT_DUSTER_B_AX_88_R2', 'BR', 'RARE',   true),
            $this->version('ALT_DUSTER_B_AX_88_C',  'AX', 'COMMON', false),
            $this->version('ALT_DUSTER_B_AX_88_R1', 'AX', 'RARE',   false),
        ]);
        $this->mockOwned([]);

        $versions = $this->service->listCards($this->user)['items'][0]['versions'];

        $this->assertSame('ALT_DUSTER_B_AX_88_C',  $versions[0]['reference']);
        $this->assertSame('ALT_DUSTER_B_AX_88_R1', $versions[1]['reference']);
        $this->assertSame('ALT_DUSTER_B_AX_88_R2', $versions[2]['reference']);
    }

    public function testOwnedSumsFoilAndNonFoilAndMergesCoreks(): void
    {
        $this->mockUniverse([$this->version('ALT_CORE_B_AX_01_C', 'AX', 'COMMON', false, 'CHARACTER', 'CORE')]);
        // 2 (CORE non-foil) + 1 (CORE foil, same ref appears twice) + 1 (COREKS) → owned 4 on the CORE ref
        $this->viewRepository->method('findOwnedCardQuantities')->willReturn([
            ['faction' => 'AX', 'cardSet' => 'CORE',   'cardReference' => 'ALT_CORE_B_AX_01_C',   'quantity' => 2],
            ['faction' => 'AX', 'cardSet' => 'CORE',   'cardReference' => 'ALT_CORE_B_AX_01_C',   'quantity' => 1],
            ['faction' => 'AX', 'cardSet' => 'COREKS', 'cardReference' => 'ALT_COREKS_B_AX_01_C', 'quantity' => 1],
        ]);

        $card = $this->service->listCards($this->user)['items'][0];

        $this->assertSame(4, $card['versions'][0]['owned']);
    }

    public function testFactionFilterMatchesRealVersionFactionAndKeepsOnlyMatchingVersions(): void
    {
        $this->mockUniverse($this->transfugeCardVersions());
        $this->mockOwned([]);

        // faction[]=BR must return the (Axiom) card via its Bravos R2, showing ONLY that version.
        $result = $this->service->listCards($this->user, factionFilter: ['BR']);

        $this->assertSame(1, $result['totalItems']);
        $versions = $result['items'][0]['versions'];
        $this->assertCount(1, $versions);
        $this->assertSame('ALT_DUSTER_B_AX_88_R2', $versions[0]['reference']);
        $this->assertSame('BR', $versions[0]['faction']);
    }

    public function testFactionFilterAxKeepsInFactionVersionsAndDropsTheTransfuge(): void
    {
        $this->mockUniverse($this->transfugeCardVersions());
        $this->mockOwned([]);

        $result   = $this->service->listCards($this->user, factionFilter: ['AX']);
        $versions = $result['items'][0]['versions'];

        $this->assertCount(2, $versions); // C and R1, not the BR R2
        $this->assertSame(['ALT_DUSTER_B_AX_88_C', 'ALT_DUSTER_B_AX_88_R1'], array_column($versions, 'reference'));
    }

    public function testRarityFilterRareKeepsBothR1AndR2(): void
    {
        $this->mockUniverse($this->transfugeCardVersions());
        $this->mockOwned([]);

        $versions = $this->service->listCards($this->user, rarityFilter: ['RARE'])['items'][0]['versions'];

        $this->assertSame(['ALT_DUSTER_B_AX_88_R1', 'ALT_DUSTER_B_AX_88_R2'], array_column($versions, 'reference'));
    }

    public function testRarityFilterCommonKeepsOnlyTheCommonVersion(): void
    {
        $this->mockUniverse($this->transfugeCardVersions());
        $this->mockOwned([]);

        $versions = $this->service->listCards($this->user, rarityFilter: ['COMMON'])['items'][0]['versions'];

        $this->assertCount(1, $versions);
        $this->assertSame('COMMON', $versions[0]['rarity']);
    }

    public function testCardHiddenWhenAllVersionsEliminated(): void
    {
        // A pure Axiom card (no transfuge); filtering for an unrelated faction eliminates everything.
        $this->mockUniverse([
            $this->version('ALT_DUSTER_B_AX_88_C',  'AX', 'COMMON', false),
            $this->version('ALT_DUSTER_B_AX_88_R1', 'AX', 'RARE',   false),
        ]);
        $this->mockOwned([]);

        $result = $this->service->listCards($this->user, factionFilter: ['LY']);

        $this->assertSame(0, $result['totalItems']);
        $this->assertSame([], $result['items']);
    }

    public function testCopiesFilterKeepsOnlyVersionsMatchingBucket(): void
    {
        $this->mockUniverse($this->transfugeCardVersions());
        // C owned 0, R1 owned 3, R2 owned 0
        $this->mockOwned(['ALT_DUSTER_B_AX_88_R1' => 3]);

        // copies[]=3 is version-level: card included but ONLY the R1 version (owned 3) survives.
        $result = $this->service->listCards($this->user, copiesFilter: ['3']);

        $this->assertSame(1, $result['totalItems']);
        $versions = $result['items'][0]['versions'];
        $this->assertCount(1, $versions);
        $this->assertSame('ALT_DUSTER_B_AX_88_R1', $versions[0]['reference']);
        $this->assertSame(3, $versions[0]['owned']);
    }

    public function testCopiesFilterExcludesCardWhenNoVersionMatches(): void
    {
        $this->mockUniverse($this->transfugeCardVersions());
        $this->mockOwned(['ALT_DUSTER_B_AX_88_C' => 1]); // only "1-2" present

        $result = $this->service->listCards($this->user, copiesFilter: ['4plus']);

        $this->assertSame(0, $result['totalItems']);
    }

    public function testCopiesFilterZeroBucketMatchesUnownedVersions(): void
    {
        $this->mockUniverse($this->transfugeCardVersions());
        $this->mockOwned([]); // everything owned 0

        $result = $this->service->listCards($this->user, copiesFilter: ['0']);

        $this->assertSame(1, $result['totalItems']);
        $this->assertCount(3, $result['items'][0]['versions']); // every version owned 0 → all kept
    }

    public function testCardSetFilterIsCardLevel(): void
    {
        $this->mockUniverse([
            $this->version('ALT_DUSTER_B_AX_88_C', 'AX', 'COMMON', false, 'CHARACTER', 'DUSTER'),
            $this->version('ALT_CORE_B_AX_01_C',   'AX', 'COMMON', false, 'CHARACTER', 'CORE'),
        ]);
        $this->mockOwned([]);

        $result = $this->service->listCards($this->user, cardSetFilter: ['CORE']);

        $this->assertSame(1, $result['totalItems']);
        $this->assertSame('CORE', $result['items'][0]['cardSet']);
    }

    public function testCardTypeFilterIsCardLevel(): void
    {
        $this->mockUniverse([
            $this->version('ALT_DUSTER_B_AX_88_C', 'AX', 'COMMON', false, 'CHARACTER'),
            $this->version('ALT_DUSTER_B_AX_97_C', 'AX', 'COMMON', false, 'SPELL'),
        ]);
        $this->mockOwned([]);

        $result = $this->service->listCards($this->user, cardTypeFilter: ['SPELL']);

        $this->assertSame(1, $result['totalItems']);
        $this->assertSame('SPELL', $result['items'][0]['cardType']);
    }

    public function testNameFilterIsCaseInsensitivePartialOnLocale(): void
    {
        $this->mockUniverse([
            $this->version('ALT_DUSTER_B_AX_88_C', 'AX', 'COMMON', false, 'CHARACTER', 'DUSTER', 'Ira, Fair Attendee'),
            $this->version('ALT_DUSTER_B_AX_94_C', 'AX', 'COMMON', false, 'CHARACTER', 'DUSTER', 'Leonardo da Vinci'),
        ]);
        $this->mockOwned([]);

        $result = $this->service->listCards($this->user, nameFilter: 'leonardo');

        $this->assertSame(1, $result['totalItems']);
        $this->assertSame('Leonardo da Vinci', $result['items'][0]['name']);
    }

    public function testExaltedCardHasSingleVersion(): void
    {
        $this->mockUniverse([$this->version('ALT_DUSTER_B_AX_95_E', 'AX', 'EXALTED', false)]);
        $this->mockOwned([]);

        $card = $this->service->listCards($this->user)['items'][0];

        $this->assertCount(1, $card['versions']);
        $this->assertSame('EXALTED', $card['versions'][0]['rarity']);
    }

    public function testLocaleFlattensNameAndImagePath(): void
    {
        $this->mockUniverse([$this->version('ALT_DUSTER_B_AX_88_C', 'AX', 'COMMON', false)]);
        $this->mockOwned([]);

        $card = $this->service->listCards($this->user, locale: 'fr')['items'][0];

        $this->assertSame('Ira, Fair Attendee (fr)', $card['name']);
        $this->assertSame('ALT_DUSTER_B_AX_88_C-fr.jpg', $card['versions'][0]['imagePath']);
    }

    public function testLocaleFallsBackToEnThenFr(): void
    {
        $this->mockUniverse([$this->version('ALT_DUSTER_B_AX_88_C', 'AX', 'COMMON', false)]);
        $this->mockOwned([]);

        // Italian is absent from the builder → falls back to en.
        $card = $this->service->listCards($this->user, locale: 'it')['items'][0];

        $this->assertSame('Ira, Fair Attendee', $card['name']);
    }

    public function testPaginationSlicesCardsAndReportsTotals(): void
    {
        $versions = [];
        for ($i = 1; $i <= 5; $i++) {
            $versions[] = $this->version(sprintf('ALT_DUSTER_B_AX_%02d_C', $i), 'AX', 'COMMON', false);
        }
        $this->mockUniverse($versions);
        $this->mockOwned([]);

        $result = $this->service->listCards($this->user, page: 2, itemsPerPage: 2);

        $this->assertSame(5, $result['totalItems']);
        $this->assertSame(3, $result['totalPages']);
        $this->assertSame(2, $result['page']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('ALT_DUSTER_B_AX_03', $result['items'][0]['baseReference']);
        $this->assertSame('ALT_DUSTER_B_AX_04', $result['items'][1]['baseReference']);
    }

    public function testItemsPerPageIsClampedToMax(): void
    {
        $this->mockUniverse($this->transfugeCardVersions());
        $this->mockOwned([]);

        $result = $this->service->listCards($this->user, itemsPerPage: 9999);

        $this->assertSame(CollectionPlaysetCardsService::MAX_ITEMS_PER_PAGE, $result['itemsPerPage']);
    }

    public function testNativeOrderIsPreservedAcrossCards(): void
    {
        $this->mockUniverse([
            $this->version('ALT_DUSTER_B_AX_94_C', 'AX', 'COMMON', false),
            $this->version('ALT_DUSTER_B_AX_88_C', 'AX', 'COMMON', false),
            $this->version('ALT_DUSTER_B_AX_91_C', 'AX', 'COMMON', false),
        ]);
        $this->mockOwned([]);

        $bases = array_column($this->service->listCards($this->user)['items'], 'baseReference');

        $this->assertSame(['ALT_DUSTER_B_AX_94', 'ALT_DUSTER_B_AX_88', 'ALT_DUSTER_B_AX_91'], $bases);
    }

    public function testAggregatesNonBProductOwnedCopiesIntoTheBVersion(): void
    {
        // Universe lists only the B printing; the user owns one A printing and one B printing.
        $this->mockUniverse([$this->version('ALT_DUSTER_B_AX_95_E', 'AX', 'EXALTED', false)]);
        $this->mockOwned([
            'ALT_DUSTER_A_AX_95_E' => 1,
            'ALT_DUSTER_B_AX_95_E' => 1,
        ]);

        $version = $this->service->listCards($this->user)['items'][0]['versions'][0];

        $this->assertSame('ALT_DUSTER_B_AX_95_E', $version['reference']);
        $this->assertSame(2, $version['owned']); // A copy folded into the B version
        // Owning an A printing proves the version exists in several products → list is exposed.
        $this->assertSame(['A', 'B'], $version['ownedCardProducts']);
    }

    public function testDedupesMultiProductUniverseKeepingTheBVersion(): void
    {
        // The universe lists both an A alt-art and the B booster of the same card+rarity.
        // Order them A-first to prove the B printing is still the one kept.
        $this->mockUniverse([
            $this->version('ALT_DUSTER_A_AX_95_E', 'AX', 'EXALTED', false),
            $this->version('ALT_DUSTER_B_AX_95_E', 'AX', 'EXALTED', false),
        ]);
        $this->mockOwned(['ALT_DUSTER_A_AX_95_E' => 1]);

        $result = $this->service->listCards($this->user);

        $this->assertSame(1, $result['totalItems']);
        $card = $result['items'][0];
        $this->assertSame('ALT_DUSTER_B_AX_95', $card['baseReference']);
        $this->assertCount(1, $card['versions']); // A and B collapsed into one
        $version = $card['versions'][0];
        $this->assertSame('ALT_DUSTER_B_AX_95_E', $version['reference']);
        $this->assertSame(1, $version['owned']);
        $this->assertSame(['A'], $version['ownedCardProducts']); // user owns only the A printing
    }

    public function testMultiProductVersionExposesEmptyOwnedProductsWhenNothingOwned(): void
    {
        $this->mockUniverse([
            $this->version('ALT_DUSTER_A_AX_95_E', 'AX', 'EXALTED', false),
            $this->version('ALT_DUSTER_B_AX_95_E', 'AX', 'EXALTED', false),
        ]);
        $this->mockOwned([]);

        $version = $this->service->listCards($this->user)['items'][0]['versions'][0];

        $this->assertArrayHasKey('ownedCardProducts', $version);
        $this->assertSame([], $version['ownedCardProducts']);
    }

    public function testSummaryAggregatesOverTheWholeFilteredResultNotJustThePage(): void
    {
        $this->mockUniverse([
            ...$this->transfugeCardVersions(),                                  // ALT_DUSTER_B_AX_88: C, R1, R2
            $this->version('ALT_DUSTER_B_AX_94_C', 'AX', 'COMMON', false),      // a second, single-version card
        ]);
        $this->mockOwned([
            'ALT_DUSTER_B_AX_88_C' => 2,
            'ALT_DUSTER_B_AX_94_C' => 4,
        ]);

        // Page size of 1 proves the summary spans every page, not just the returned slice.
        $summary = $this->service->listCards($this->user, page: 1, itemsPerPage: 1)['summary'];

        $this->assertSame(2, $summary['totalCards']);    // two cards match
        $this->assertSame(4, $summary['totalVersions']); // 3 versions + 1 version
        $this->assertSame(6, $summary['totalOwned']);    // 2 + 0 + 0 + 4
        // owned values across the 4 versions: 2 (1-2), 0, 0, 4 (4plus)
        $this->assertSame(['0' => 2, '1-2' => 1, '3' => 0, '4plus' => 1], $summary['ownedBuckets']);
    }

    public function testSummaryOwnedBucketsCountVersionsPerTier(): void
    {
        // Four single-version cards, one in each tier: owned 0, 2, 3, 5.
        $this->mockUniverse([
            $this->version('ALT_DUSTER_B_AX_01_C', 'AX', 'COMMON', false),
            $this->version('ALT_DUSTER_B_AX_02_C', 'AX', 'COMMON', false),
            $this->version('ALT_DUSTER_B_AX_03_C', 'AX', 'COMMON', false),
            $this->version('ALT_DUSTER_B_AX_04_C', 'AX', 'COMMON', false),
        ]);
        $this->mockOwned([
            'ALT_DUSTER_B_AX_02_C' => 2,
            'ALT_DUSTER_B_AX_03_C' => 3,
            'ALT_DUSTER_B_AX_04_C' => 5,
        ]);

        $summary = $this->service->listCards($this->user)['summary'];

        $this->assertSame(['0' => 1, '1-2' => 1, '3' => 1, '4plus' => 1], $summary['ownedBuckets']);
        // The buckets partition the versions, and totalOwned is unchanged.
        $this->assertSame($summary['totalVersions'], array_sum($summary['ownedBuckets']));
        $this->assertSame(10, $summary['totalOwned']);
    }

    public function testSummaryReflectsFilters(): void
    {
        $this->mockUniverse([
            ...$this->transfugeCardVersions(),
            $this->version('ALT_DUSTER_B_AX_94_C', 'AX', 'COMMON', false),
        ]);
        $this->mockOwned([]);

        // Keep only commons → one version per card, both cards still present.
        $summary = $this->service->listCards($this->user, rarityFilter: ['COMMON'])['summary'];

        $this->assertSame(2, $summary['totalCards']);
        $this->assertSame(2, $summary['totalVersions']); // R1/R2 filtered out
        $this->assertSame(0, $summary['totalOwned']);
    }

    public function testSingleProductVersionOmitsOwnedCardProducts(): void
    {
        $this->mockUniverse([$this->version('ALT_DUSTER_B_AX_88_C', 'AX', 'COMMON', false)]);
        $this->mockOwned(['ALT_DUSTER_B_AX_88_C' => 2]);

        $version = $this->service->listCards($this->user)['items'][0]['versions'][0];

        $this->assertArrayNotHasKey('ownedCardProducts', $version);
    }
}
