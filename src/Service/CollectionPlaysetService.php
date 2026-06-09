<?php

namespace App\Service;

use App\Client\AlteredCoreClient;
use App\Entity\User;
use App\Repository\CollectionCardViewRepository;

/**
 * Builds the per-(faction × cardSet × quantity-bucket) breakdown of a user's collection.
 *
 * Buckets:
 *   "0"  → cards the user does NOT own (the set's universe minus the owned references),
 *   "1"  → owned with quantity exactly 1,
 *   "2"  → owned with quantity exactly 2,
 *   "3+" → owned with quantity 3 or more.
 *
 * The grid is always emitted in full: every faction × set combination appears, even when
 * all of its counts are zero. The "universe" (total existing references) is fetched from
 * altered-core, one (set, faction) couple at a time, and cached there for 1 hour.
 *
 * Some editions share the same cards and are merged into a single output set (see
 * {@see self::SET_ALIASES}): CORE and COREKS hold identical cards, so a card owned across both
 * is collapsed to one card whose quantity is the sum across editions (1×COREKS + 2×CORE counts
 * as a single card ×3, bucket "3+"). This merge happens at the card level — hence the service
 * works from raw per-card quantities and buckets them itself, rather than summing SQL buckets
 * which would double-count the same card. The merged set's universe is the canonical edition's
 * (CORE), since COREKS adds no new references.
 *
 * Both the universe lookup and the owned counts are restricted to {@see self::RARITIES} and
 * {@see self::CARD_TYPES} (UNIQUE rarities, tokens and other card types are excluded), so that
 * the "0" bucket — universe minus owned — stays internally consistent.
 *
 * The response carries three views of the same data:
 *   - "byFactionAndSet" — the full faction × set grid,
 *   - "byFaction"       — totals per faction, summed across all sets,
 *   - "bySet"           — totals per set, summed across all factions.
 * Each cardReference belongs to exactly one (faction, set), so the aggregates are plain sums
 * of the grid buckets with no double counting.
 */
class CollectionPlaysetService
{
    /** Card sets included in the playset breakdown, in output order. */
    public const SETS = ['CORE', 'ALIZE', 'BISE', 'CYCLONE', 'DUSTER', 'EOLE'];

    /**
     * Editions that hold the same cards as another set and are merged into it: source set code
     * (as stored on the cards) → output set code (must be one of {@see self::SETS}). Cards in a
     * source edition are folded onto their canonical edition before bucketing.
     */
    public const SET_ALIASES = ['COREKS' => 'CORE'];

    /** Altered factions, in output order. */
    public const FACTIONS = ['AX', 'BR', 'LY', 'MU', 'OR', 'YZ'];

    /** Rarities counted on both sides of the bucket-0 computation. */
    public const RARITIES = ['COMMON', 'RARE', 'EXALTED'];

    /** Card types counted on both sides of the bucket-0 computation. */
    public const CARD_TYPES = ['CHARACTER', 'SPELL', 'PERMANENT', 'LANDMARK_PERMANENT', 'EXPEDITION_PERMANENT'];

    public function __construct(
        private readonly CollectionCardViewRepository $viewRepository,
        private readonly AlteredCoreClient            $alteredCoreClient,
    ) {}

    /**
     * @return array{
     *     byFactionAndSet: list<array{faction:string, cardSet:string, quantities:array{0:int, 1:int, 2:int, '3+':int}}>,
     *     byFaction: list<array{faction:string, quantities:array{0:int, 1:int, 2:int, '3+':int}}>,
     *     bySet: list<array{cardSet:string, quantities:array{0:int, 1:int, 2:int, '3+':int}}>
     * }
     */
    public function computePlayset(User $user): array
    {
        // Query every underlying edition (the output sets plus their merged sources), then fold
        // each card onto its canonical edition and bucket the merged per-card quantities.
        $sourceSets = array_values(array_unique(array_merge(self::SETS, array_keys(self::SET_ALIASES))));
        $rows       = $this->viewRepository->findOwnedCardQuantities($user, $sourceSets, self::RARITIES, self::CARD_TYPES);
        $owned      = $this->mergeAndBucket($rows);

        $byFactionAndSet = [];
        $factionTotals   = array_fill_keys(self::FACTIONS, ['0' => 0, '1' => 0, '2' => 0, '3+' => 0]);
        $setTotals       = array_fill_keys(self::SETS, ['0' => 0, '1' => 0, '2' => 0, '3+' => 0]);

        foreach (self::SETS as $set) {
            foreach (self::FACTIONS as $faction) {
                $buckets = $owned[$faction . '|' . $set] ?? ['1' => 0, '2' => 0, '3+' => 0];

                $ownedNonZero = $buckets['1'] + $buckets['2'] + $buckets['3+'];
                $universe     = $this->alteredCoreClient->countCardsBySetAndFaction($set, $faction, self::RARITIES, self::CARD_TYPES);

                $quantities = [
                    '0'  => max(0, $universe - $ownedNonZero),
                    '1'  => $buckets['1'],
                    '2'  => $buckets['2'],
                    '3+' => $buckets['3+'],
                ];

                $byFactionAndSet[] = [
                    'faction'    => $faction,
                    'cardSet'    => $set,
                    'quantities' => $quantities,
                ];

                foreach (['0', '1', '2', '3+'] as $bucket) {
                    $factionTotals[$faction][$bucket] += $quantities[$bucket];
                    $setTotals[$set][$bucket]         += $quantities[$bucket];
                }
            }
        }

        $byFaction = array_map(
            static fn (string $faction): array => ['faction' => $faction, 'quantities' => $factionTotals[$faction]],
            self::FACTIONS,
        );
        $bySet = array_map(
            static fn (string $set): array => ['cardSet' => $set, 'quantities' => $setTotals[$set]],
            self::SETS,
        );

        return [
            'byFactionAndSet' => $byFactionAndSet,
            'byFaction'       => $byFaction,
            'bySet'           => $bySet,
        ];
    }

    /**
     * Fold owned cards onto their canonical edition, sum the quantity of each card across the
     * merged editions, then bucket the merged quantities per faction × (canonical) set.
     *
     * @param  list<array{faction:string, cardSet:string, cardReference:string, quantity:int}> $rows
     * @return array<string, array{1:int, 2:int, '3+':int}>  keyed by "FACTION|CARDSET"
     */
    private function mergeAndBucket(array $rows): array
    {
        // Sum each card's quantity across editions, keyed by its canonical (de-aliased) reference.
        $merged = [];
        foreach ($rows as $row) {
            $key = $this->canonicalReference($row['cardReference']);
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'faction'  => $row['faction'],
                    'cardSet'  => self::SET_ALIASES[$row['cardSet']] ?? $row['cardSet'],
                    'quantity' => 0,
                ];
            }
            $merged[$key]['quantity'] += $row['quantity'];
        }

        $owned = [];
        foreach ($merged as $card) {
            if ($card['quantity'] <= 0) {
                continue;
            }
            $gridKey = $card['faction'] . '|' . $card['cardSet'];
            $owned[$gridKey] ??= ['1' => 0, '2' => 0, '3+' => 0];

            $bucket = match (true) {
                $card['quantity'] === 1 => '1',
                $card['quantity'] === 2 => '2',
                default                 => '3+',
            };
            $owned[$gridKey][$bucket]++;
        }

        return $owned;
    }

    /**
     * Rewrite a reference's set token onto its canonical edition so the same card across merged
     * editions collapses to one key, e.g. ALT_COREKS_B_AX_01_C → ALT_CORE_B_AX_01_C.
     */
    private function canonicalReference(string $reference): string
    {
        foreach (self::SET_ALIASES as $source => $target) {
            $prefix = 'ALT_' . $source . '_';
            if (str_starts_with($reference, $prefix)) {
                return 'ALT_' . $target . '_' . substr($reference, strlen($prefix));
            }
        }

        return $reference;
    }
}
