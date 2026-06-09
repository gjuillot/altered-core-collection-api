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
    public const SETS = ['CORE', 'COREKS', 'ALIZE', 'BISE', 'CYCLONE', 'DUSTER', 'EOLE'];

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
        $owned = $this->viewRepository->countOwnedBucketsByFactionAndSet($user, self::SETS, self::RARITIES, self::CARD_TYPES);

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
}
