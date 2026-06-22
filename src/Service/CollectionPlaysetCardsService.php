<?php

namespace App\Service;

use App\Client\AlteredCoreClient;
use App\Entity\User;
use App\Repository\CollectionCardViewRepository;

/**
 * Powers GET /api/collection/playset/cards — the "shopping list" view that walks the WHOLE playset
 * universe card by card, including cards the connected user owns in 0 copies (which /api/collection
 * cannot surface, as it only returns the owned cards).
 *
 * The universe (every standard B-product card in {@see CollectionPlaysetService::SETS}, restricted
 * to {@see CollectionPlaysetService::RARITIES} and {@see CollectionPlaysetService::CARD_TYPES}) is
 * fetched from altered-core and joined, server-side, with the user's owned quantities. The perimeter
 * is intentionally the same as {@see CollectionPlaysetService} so both playset endpoints agree.
 *
 * A "card" groups all the versions that share a base reference (the reference minus its rarity
 * suffix), e.g. ALT_DUSTER_B_AX_88 groups _C, _R1, _R2. Versions are ordered C → R1 → R2 → E.
 *
 * CORE and COREKS hold the same cards: COREKS-owned copies are folded onto their CORE reference and
 * summed (see {@see CollectionPlaysetService::SET_ALIASES}); the universe itself is queried for CORE
 * only.
 *
 * The same card is also printed in several "products" (the reference's 3rd token: B = booster, plus
 * A/P alt-art or promo printings). These are collapsed onto the booster (B) printing: a version is
 * always displayed as its B reference, and its `owned` sums the user's copies across every product
 * of that card+rarity (e.g. owning one A printing and one B printing shows the B version, owned 2).
 * See {@see self::canonicalReference()}. When a card+rarity is printed in more than one product, the
 * version additionally carries `ownedCardProducts` — the list of products the user actually owns a
 * copy of (omitted entirely for single-product versions, where it would always be just ["B"]).
 *
 * Filtering happens at two levels (see {@see self::buildItems()}):
 *   - VERSION level — rarity[], faction[] (on the real version faction) and copies[] (on the
 *     version's owned bucket) remove versions out of the requested perimeter; a removed version is
 *     neither displayed nor counted.
 *   - CARD level — cardSet[], cardType[] and name include/exclude the whole card.
 * A card is returned iff at least one version survives the version-level filters AND it passes the
 * card-level filters; when returned, all its surviving versions are shown.
 */
class CollectionPlaysetCardsService
{
    /** Suffix → display order of versions inside a card. */
    private const VERSION_ORDER = ['C' => 0, 'R1' => 1, 'R2' => 2, 'E' => 3];

    public const COPIES_BUCKETS = ['0', '1-2', '3', '4plus'];

    public const DEFAULT_ITEMS_PER_PAGE = 30;
    public const MAX_ITEMS_PER_PAGE     = 100;

    public function __construct(
        private readonly CollectionCardViewRepository $viewRepository,
        private readonly AlteredCoreClient            $alteredCoreClient,
    ) {}

    /**
     * @param  string[] $cardSetFilter   set references (card level), e.g. ["DUSTER"]
     * @param  string[] $factionFilter   faction codes on the real version faction, e.g. ["AX"]
     * @param  string[] $cardTypeFilter  card type references (card level), e.g. ["CHARACTER"]
     * @param  string[] $rarityFilter    perimeter rarities to keep, subset of RARITIES
     * @param  string[] $copiesFilter    subset of {@see self::COPIES_BUCKETS}
     *
     * @return array{
     *     summary: array{totalCards:int, totalVersions:int, totalOwned:int, ownedBuckets:array<string,int>},
     *     items: list<array<string,mixed>>,
     *     page: int,
     *     itemsPerPage: int,
     *     totalItems: int,
     *     totalPages: int
     * }
     */
    public function listCards(
        User    $user,
        string  $locale = 'en',
        array   $cardSetFilter = [],
        array   $factionFilter = [],
        array   $cardTypeFilter = [],
        array   $rarityFilter = [],
        ?string $nameFilter = null,
        array   $copiesFilter = [],
        int     $page = 1,
        int     $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE,
    ): array {
        $page         = max(1, $page);
        $itemsPerPage = max(1, min(self::MAX_ITEMS_PER_PAGE, $itemsPerPage));

        $universe = $this->alteredCoreClient->fetchPlaysetUniverse(
            CollectionPlaysetService::SETS,
            CollectionPlaysetService::RARITIES,
            CollectionPlaysetService::CARD_TYPES,
        );

        $owned = $this->ownedByCanonicalReference($user);
        $cards = $this->groupIntoCards($universe, $owned['quantities'], $owned['products']);

        $matching = $this->buildItems(
            $cards,
            $locale,
            $cardSetFilter,
            $factionFilter,
            $cardTypeFilter,
            $rarityFilter,
            $nameFilter,
            $copiesFilter,
        );

        // Totals over the whole filtered result (every matching card, all pages), not just the page.
        // ownedBuckets counts versions per owned tier (same tiers as the copies[] filter).
        $totalVersions = 0;
        $totalOwned    = 0;
        $ownedBuckets  = array_fill_keys(self::COPIES_BUCKETS, 0);
        foreach ($matching as $card) {
            foreach ($card['versions'] as $version) {
                $totalVersions++;
                $totalOwned += $version['owned'];
                $ownedBuckets[$this->ownedBucketLabel($version['owned'])]++;
            }
        }

        $totalItems = count($matching);
        $items      = array_slice($matching, ($page - 1) * $itemsPerPage, $itemsPerPage);

        return [
            'summary' => [
                'totalCards'    => $totalItems,
                'totalVersions' => $totalVersions,
                'totalOwned'    => $totalOwned,
                'ownedBuckets'  => $ownedBuckets,
            ],
            'items'        => array_values($items),
            'page'         => $page,
            'itemsPerPage' => $itemsPerPage,
            'totalItems'   => $totalItems,
            'totalPages'   => (int) ceil($totalItems / $itemsPerPage),
        ];
    }

    /**
     * The user's owned cards aggregated per canonical reference (foil + non-foil, CORE + COREKS, and
     * all products folded together), restricted to the playset perimeter. Returns both the summed
     * quantity and the distinct set of products actually owned (so versions printed in several
     * products can expose which ones the user holds).
     *
     * @return array{quantities: array<string,int>, products: array<string, list<string>>}
     */
    private function ownedByCanonicalReference(User $user): array
    {
        $sourceSets = array_values(array_unique(array_merge(
            CollectionPlaysetService::SETS,
            array_keys(CollectionPlaysetService::SET_ALIASES),
        )));

        $rows = $this->viewRepository->findOwnedCardQuantities(
            $user,
            $sourceSets,
            CollectionPlaysetService::RARITIES,
            CollectionPlaysetService::CARD_TYPES,
        );

        $quantities  = [];
        $productSets = [];
        foreach ($rows as $row) {
            $ref              = $this->canonicalReference($row['cardReference']);
            $quantities[$ref] = ($quantities[$ref] ?? 0) + $row['quantity'];

            if ($row['quantity'] > 0) {
                $product = explode('_', $row['cardReference'])[2] ?? '';
                if ($product !== '') {
                    $productSets[$ref][$product] = true;
                }
            }
        }

        $products = [];
        foreach ($productSets as $ref => $set) {
            $list = array_keys($set);
            sort($list);
            $products[$ref] = $list;
        }

        return ['quantities' => $quantities, 'products' => $products];
    }

    /**
     * Group universe versions into cards keyed by their product-normalised base reference,
     * preserving the universe's native order (first appearance wins). Within a card there is one
     * version per rarity suffix: when the universe lists several products for the same card+suffix
     * (e.g. an A alt-art alongside the B booster), the B printing is kept for display and the others
     * are dropped — their owned copies are already folded into the B version by canonicalReference.
     * Versions are ordered C → R1 → R2 → E.
     *
     * @param  list<array<string,mixed>>      $universe
     * @param  array<string, int>             $ownedByReference
     * @param  array<string, list<string>>    $ownedProductsByReference
     * @return list<array<string,mixed>>
     */
    private function groupIntoCards(array $universe, array $ownedByReference, array $ownedProductsByReference): array
    {
        $cards = [];
        $order = [];

        foreach ($universe as $card) {
            $parts         = explode('_', $card['reference']);
            $product       = $parts[2] ?? '';
            $suffix        = $parts[5] ?? '';
            $baseReference = $this->baseReference($card['reference']);

            if (!isset($cards[$baseReference])) {
                $order[$baseReference] = count($order);
                $cards[$baseReference] = [
                    'baseReference' => $baseReference,
                    'name'          => $card['name'],
                    'set'           => $card['set'],
                    'cardType'      => $card['cardType'],
                    'bySuffix'      => [],
                ];
            }

            $existing = $cards[$baseReference]['bySuffix'][$suffix] ?? null;

            if ($existing === null) {
                $cards[$baseReference]['bySuffix'][$suffix] = [
                    'product'  => $product,
                    'products' => [$product],
                    'version'  => $this->buildVersion($card, $suffix, $ownedByReference),
                ];
                continue;
            }

            // Record every product this card+suffix is printed in (drives the multi-product flag),
            // and prefer the booster (B) printing for display.
            if (!in_array($product, $existing['products'], true)) {
                $cards[$baseReference]['bySuffix'][$suffix]['products'][] = $product;
            }
            if ($product === 'B' && $existing['product'] !== 'B') {
                $cards[$baseReference]['bySuffix'][$suffix]['product'] = 'B';
                $cards[$baseReference]['bySuffix'][$suffix]['version'] = $this->buildVersion($card, $suffix, $ownedByReference);
            }
        }

        $result = [];
        foreach ($cards as $card) {
            $versions = [];
            foreach (array_values($card['bySuffix']) as $entry) {
                $version       = $entry['version'];
                $canonical     = $this->canonicalReference($version['reference']);
                $ownedProducts = $ownedProductsByReference[$canonical] ?? [];

                // "Exists in several products" = the catalog lists more than one printing, or the
                // user owns a printing that proves another exists. Only then expose the owned list.
                $allProducts = array_unique(array_merge($entry['products'], $ownedProducts));
                if (count($allProducts) > 1) {
                    $version['ownedCardProducts'] = $ownedProducts;
                }

                $versions[] = $version;
            }
            usort($versions, static fn (array $a, array $b): int => $a['_rank'] <=> $b['_rank']);

            $result[] = [
                'baseReference' => $card['baseReference'],
                'name'          => $card['name'],
                'set'           => $card['set'],
                'cardType'      => $card['cardType'],
                'versions'      => $versions,
            ];
        }

        // Emit the cards in the universe's native order.
        usort($result, static fn (array $a, array $b): int => $order[$a['baseReference']] <=> $order[$b['baseReference']]);

        return $result;
    }

    /**
     * Build the internal version record for one universe entry. `_rank` orders versions C→R1→R2→E
     * and is stripped before output.
     *
     * @param  array<string,mixed> $card
     * @param  array<string, int>  $ownedByReference
     * @return array<string,mixed>
     */
    private function buildVersion(array $card, string $suffix, array $ownedByReference): array
    {
        return [
            'reference'                 => $card['reference'],
            'collectorNumberFormatedId' => $card['collectorNumberFormatedId'],
            'faction'                   => $card['faction']['code'] ?? null,
            'rarity'                    => $card['rarity']['reference'] ?? null,
            'transfuge'                 => (bool) $card['transfuge'],
            'owned'                     => $ownedByReference[$this->canonicalReference($card['reference'])] ?? 0,
            'imagePath'                 => $card['imagePath'],
            '_rank'                     => self::VERSION_ORDER[$suffix] ?? 99,
        ];
    }

    /**
     * Apply version-level then card-level filters and shape the surviving cards for output.
     *
     * @param  list<array<string,mixed>> $cards
     * @param  string[] $cardSetFilter
     * @param  string[] $factionFilter
     * @param  string[] $cardTypeFilter
     * @param  string[] $rarityFilter
     * @param  string[] $copiesFilter
     * @return list<array<string,mixed>>
     */
    private function buildItems(
        array   $cards,
        string  $locale,
        array   $cardSetFilter,
        array   $factionFilter,
        array   $cardTypeFilter,
        array   $rarityFilter,
        ?string $nameFilter,
        array   $copiesFilter,
    ): array {
        $items = [];

        foreach ($cards as $card) {
            // Card-level filters that don't depend on the surviving versions.
            $setReference = $card['set']['reference'] ?? null;
            if (!empty($cardSetFilter) && !in_array($setReference, $cardSetFilter, true)) {
                continue;
            }
            $cardTypeReference = $card['cardType']['reference'] ?? null;
            if (!empty($cardTypeFilter) && !in_array($cardTypeReference, $cardTypeFilter, true)) {
                continue;
            }
            $name = $this->localize($card['name'], $locale);
            if ($nameFilter !== null && $nameFilter !== '' && ($name === null || stripos($name, $nameFilter) === false)) {
                continue;
            }

            // Version-level removal filters: rarity[], faction[] (on the real version faction) and
            // copies[] (on the version's owned bucket). A version is kept only if it survives all three.
            $versions = array_filter($card['versions'], function (array $v) use ($rarityFilter, $factionFilter, $copiesFilter): bool {
                if (!empty($rarityFilter) && !in_array($v['rarity'], $rarityFilter, true)) {
                    return false;
                }
                if (!empty($factionFilter) && !in_array($v['faction'], $factionFilter, true)) {
                    return false;
                }
                if (!empty($copiesFilter) && !$this->ownedMatchesBucket($v['owned'], $copiesFilter)) {
                    return false;
                }
                return true;
            });

            if (empty($versions)) {
                continue; // every version eliminated → card hidden
            }

            $items[] = [
                'baseReference' => $card['baseReference'],
                'name'          => $name,
                'cardSet'       => $setReference,
                'cardType'      => $cardTypeReference,
                'versions'      => array_map(
                    fn (array $v): array => $this->formatVersion($v, $locale),
                    array_values($versions),
                ),
            ];
        }

        return $items;
    }

    /**
     * Shape one version for output: localize its image and carry `ownedCardProducts` only when it
     * was set (i.e. the card+rarity is printed in several products).
     *
     * @param  array<string,mixed> $v
     * @return array<string,mixed>
     */
    private function formatVersion(array $v, string $locale): array
    {
        $version = [
            'reference'                 => $v['reference'],
            'collectorNumberFormatedId' => $v['collectorNumberFormatedId'],
            'faction'                   => $v['faction'],
            'rarity'                    => $v['rarity'],
            'transfuge'                 => $v['transfuge'],
            'owned'                     => $v['owned'],
            'imagePath'                 => $this->localize($v['imagePath'], $locale),
        ];

        if (array_key_exists('ownedCardProducts', $v)) {
            $version['ownedCardProducts'] = $v['ownedCardProducts'];
        }

        return $version;
    }

    /** @param string[] $buckets */
    private function ownedMatchesBucket(int $owned, array $buckets): bool
    {
        return in_array($this->ownedBucketLabel($owned), $buckets, true);
    }

    /** The copies bucket an owned count falls into: one of {@see self::COPIES_BUCKETS}. */
    private function ownedBucketLabel(int $owned): string
    {
        return match (true) {
            $owned <= 0 => '0',
            $owned <= 2 => '1-2',
            $owned === 3 => '3',
            default      => '4plus',
        };
    }

    /**
     * The product-normalised base reference of a card: the canonical reference (COREKS → CORE,
     * product → B) minus its rarity suffix. ALT_DUSTER_A_AX_88_R2 → ALT_DUSTER_B_AX_88. All versions
     * and products of the same card therefore share one base reference.
     */
    private function baseReference(string $reference): string
    {
        $parts = explode('_', $this->canonicalReference($reference));

        return implode('_', array_slice($parts, 0, 5));
    }

    /**
     * Rewrite a reference onto its canonical printing so owned copies match the displayed version:
     * the set token is folded onto its canonical edition (COREKS → CORE, see
     * {@see CollectionPlaysetService::SET_ALIASES}) and the product token is normalised to the
     * booster product (B). ALT_COREKS_A_AX_01_C → ALT_CORE_B_AX_01_C.
     */
    private function canonicalReference(string $reference): string
    {
        $parts = explode('_', $reference);

        // parts: [ALT, SET, PRODUCT, FACTION, NUM, SUFFIX, ...]
        if (isset($parts[1]) && isset(CollectionPlaysetService::SET_ALIASES[$parts[1]])) {
            $parts[1] = CollectionPlaysetService::SET_ALIASES[$parts[1]];
        }
        if (isset($parts[2])) {
            $parts[2] = 'B';
        }

        return implode('_', $parts);
    }

    /**
     * Flatten a multilingual field to a single string for the requested locale, falling back to
     * en, then fr, then the first available value. Already-scalar values are returned as-is.
     *
     * @param array<string,string>|string|null $value
     */
    private function localize(array|string|null $value, string $locale): ?string
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return null;
        }

        return $value[$locale] ?? $value['en'] ?? $value['fr'] ?? reset($value);
    }
}
