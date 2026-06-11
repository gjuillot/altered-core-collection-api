<?php

namespace App\Tests\Unit\Service;

use App\Client\AlteredCoreClient;
use App\Entity\User;
use App\Repository\CollectionCardViewRepository;
use App\Service\CollectionPlaysetService;
use PHPUnit\Framework\TestCase;

class CollectionPlaysetServiceTest extends TestCase
{
    private CollectionCardViewRepository $viewRepository;
    private AlteredCoreClient            $client;
    private CollectionPlaysetService     $service;
    private User                         $user;

    protected function setUp(): void
    {
        $this->viewRepository = $this->createMock(CollectionCardViewRepository::class);
        $this->client         = $this->createMock(AlteredCoreClient::class);
        $this->user           = $this->createMock(User::class);

        $this->service = new CollectionPlaysetService($this->viewRepository, $this->client);
    }

    /** @param list<array<string,mixed>> $grid */
    private function findCombo(array $grid, string $faction, string $set): array
    {
        foreach ($grid as $entry) {
            if ($entry['faction'] === $faction && $entry['cardSet'] === $set) {
                return $entry;
            }
        }
        $this->fail("Combo {$faction}/{$set} not found in byFactionAndSet.");
    }

    /** @param list<array<string,mixed>> $rows */
    private function findBy(array $rows, string $key, string $value): array
    {
        foreach ($rows as $entry) {
            if ($entry[$key] === $value) {
                return $entry;
            }
        }
        $this->fail("Entry {$key}={$value} not found.");
    }

    /**
     * Build owned-card rows for a faction × set with the requested per-bucket distribution
     * (b1 cards at qty 1, b2 at qty 2, b3plus at qty 3). References are unique per call.
     *
     * @return list<array{faction:string, cardSet:string, cardReference:string, quantity:int}>
     */
    private function ownedRows(string $faction, string $set, int $b1, int $b2, int $b3plus): array
    {
        $rows = [];
        $seq  = 0;
        foreach ([[$b1, 1], [$b2, 2], [$b3plus, 3]] as [$count, $qty]) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'faction'       => $faction,
                    'cardSet'       => $set,
                    'cardReference' => sprintf('ALT_%s_B_%s_%03d_C', $set, $faction, ++$seq + ($qty * 1000)),
                    'quantity'      => $qty,
                ];
            }
        }

        return $rows;
    }

    public function testComputePlaysetReturnsTheThreeViews(): void
    {
        $this->viewRepository->method('findOwnedCardQuantities')->willReturn([]);
        $this->client->method('countCardsBySetAndFaction')->willReturn(0);

        $result = $this->service->computePlayset($this->user);

        $this->assertSame(['byFactionAndSet', 'byFaction', 'bySet'], array_keys($result));
        $this->assertCount(count(CollectionPlaysetService::FACTIONS), $result['byFaction']);
        $this->assertCount(count(CollectionPlaysetService::SETS), $result['bySet']);
    }

    public function testComputePlaysetEmitsTheFullGrid(): void
    {
        $this->viewRepository->method('findOwnedCardQuantities')->willReturn([]);
        $this->client->method('countCardsBySetAndFaction')->willReturn(0);

        $grid = $this->service->computePlayset($this->user)['byFactionAndSet'];

        $this->assertCount(
            count(CollectionPlaysetService::SETS) * count(CollectionPlaysetService::FACTIONS),
            $grid,
        );

        // Every declared combo is present, with all four buckets in order.
        foreach (CollectionPlaysetService::SETS as $set) {
            foreach (CollectionPlaysetService::FACTIONS as $faction) {
                $combo = $this->findCombo($grid, $faction, $set);
                $this->assertSame(['0', '1', '2', '3+'], array_map('strval', array_keys($combo['quantities'])));
            }
        }
    }

    public function testCoreksIsNotEmittedAsItsOwnSet(): void
    {
        $this->viewRepository->method('findOwnedCardQuantities')->willReturn([]);
        $this->client->method('countCardsBySetAndFaction')->willReturn(0);

        $result = $this->service->computePlayset($this->user);

        $this->assertNotContains('COREKS', CollectionPlaysetService::SETS);
        foreach ($result['bySet'] as $entry) {
            $this->assertNotSame('COREKS', $entry['cardSet']);
        }
    }

    public function testComputePlaysetBucketsOwnedAndDerivesZeroFromUniverse(): void
    {
        $this->viewRepository->method('findOwnedCardQuantities')
            ->willReturn($this->ownedRows('AX', 'ALIZE', 4, 57, 57));
        // universe = 23 (not owned) + 118 (owned non-zero) = 141 for AX/ALIZE; 0 elsewhere
        $this->client->method('countCardsBySetAndFaction')
            ->willReturnCallback(
                static fn (string $set, string $faction): int =>
                    ($set === 'ALIZE' && $faction === 'AX') ? 141 : 0,
            );

        $grid = $this->service->computePlayset($this->user)['byFactionAndSet'];
        $q    = $this->findCombo($grid, 'AX', 'ALIZE')['quantities'];

        $this->assertSame(23, $q['0']);
        $this->assertSame(4,  $q['1']);
        $this->assertSame(57, $q['2']);
        $this->assertSame(57, $q['3+']);
    }

    public function testComputePlaysetZeroBucketIsZeroWhenUserOwnsWholeUniverse(): void
    {
        $this->viewRepository->method('findOwnedCardQuantities')
            ->willReturn($this->ownedRows('BR', 'CORE', 2, 3, 5));
        $this->client->method('countCardsBySetAndFaction')
            ->willReturnCallback(
                static fn (string $set, string $faction): int =>
                    ($set === 'CORE' && $faction === 'BR') ? 10 : 0,
            );

        $grid = $this->service->computePlayset($this->user)['byFactionAndSet'];
        $q    = $this->findCombo($grid, 'BR', 'CORE')['quantities'];

        $this->assertSame(0, $q['0']);
    }

    public function testComputePlaysetClampsZeroBucketAtZeroOnDataDrift(): void
    {
        // User owns more references than altered-core reports as the universe.
        $this->viewRepository->method('findOwnedCardQuantities')
            ->willReturn($this->ownedRows('YZ', 'EOLE', 5, 0, 0));
        $this->client->method('countCardsBySetAndFaction')->willReturn(3);

        $grid = $this->service->computePlayset($this->user)['byFactionAndSet'];
        $q    = $this->findCombo($grid, 'YZ', 'EOLE')['quantities'];

        $this->assertSame(0, $q['0']);
    }

    public function testComputePlaysetMergesCoreAndCoreksAtTheCardLevel(): void
    {
        // Same card owned 1×COREKS + 2×CORE → a single card ×3 (bucket "3+").
        // A CORE-only card ×1 and a COREKS-only card ×1 → two cards in bucket "1".
        $this->viewRepository->method('findOwnedCardQuantities')->willReturn([
            ['faction' => 'AX', 'cardSet' => 'CORE',   'cardReference' => 'ALT_CORE_B_AX_01_C',   'quantity' => 2],
            ['faction' => 'AX', 'cardSet' => 'COREKS', 'cardReference' => 'ALT_COREKS_B_AX_01_C', 'quantity' => 1],
            ['faction' => 'AX', 'cardSet' => 'CORE',   'cardReference' => 'ALT_CORE_B_AX_02_C',   'quantity' => 1],
            ['faction' => 'AX', 'cardSet' => 'COREKS', 'cardReference' => 'ALT_COREKS_B_AX_03_C', 'quantity' => 1],
        ]);
        // universe large enough to keep bucket 0 positive; only CORE is ever queried.
        $this->client->method('countCardsBySetAndFaction')
            ->willReturnCallback(
                static fn (string $set, string $faction): int =>
                    ($set === 'CORE' && $faction === 'AX') ? 10 : 0,
            );

        $grid = $this->service->computePlayset($this->user)['byFactionAndSet'];
        $q    = $this->findCombo($grid, 'AX', 'CORE')['quantities'];

        $this->assertSame(2, $q['1']);   // the two single-edition cards
        $this->assertSame(0, $q['2']);
        $this->assertSame(1, $q['3+']);  // 1×COREKS + 2×CORE merged into one ×3 card
        $this->assertSame(7, $q['0']);   // 10 universe − 3 owned distinct cards
    }

    public function testComputePlaysetNeverLooksUpTheCoreksUniverse(): void
    {
        $this->viewRepository->method('findOwnedCardQuantities')->willReturn([]);

        $this->client->expects($this->atLeastOnce())
            ->method('countCardsBySetAndFaction')
            ->willReturnCallback(function (string $set): int {
                $this->assertNotSame('COREKS', $set, 'COREKS universe must never be queried; CORE covers it.');
                return 0;
            });

        $this->service->computePlayset($this->user);
    }

    public function testComputePlaysetByFactionSumsAcrossAllSets(): void
    {
        // AX owns cards in two different sets; the per-faction total must sum both.
        $this->viewRepository->method('findOwnedCardQuantities')->willReturn([
            ...$this->ownedRows('AX', 'CORE',  2, 1, 0),
            ...$this->ownedRows('AX', 'ALIZE', 3, 0, 4),
        ]);
        $this->client->method('countCardsBySetAndFaction')
            ->willReturnCallback(
                static function (string $set, string $faction): int {
                    if ($faction !== 'AX') {
                        return 0;
                    }
                    return match ($set) {
                        'CORE'  => 10, // owned non-zero = 3 → bucket0 = 7
                        'ALIZE' => 20, // owned non-zero = 7 → bucket0 = 13
                        default => 0,
                    };
                },
            );

        $byFaction = $this->service->computePlayset($this->user)['byFaction'];
        $ax        = $this->findBy($byFaction, 'faction', 'AX')['quantities'];

        // 0: 7 + 13 (+ zeros from the other four sets) = 20
        $this->assertSame(20, $ax['0']);
        $this->assertSame(5,  $ax['1']);  // 2 + 3
        $this->assertSame(1,  $ax['2']);  // 1 + 0
        $this->assertSame(4,  $ax['3+']); // 0 + 4
    }

    public function testComputePlaysetBySetSumsAcrossAllFactions(): void
    {
        // Two factions own cards in CORE; the per-set total must sum both.
        $this->viewRepository->method('findOwnedCardQuantities')->willReturn([
            ...$this->ownedRows('AX', 'CORE', 2, 1, 0),
            ...$this->ownedRows('BR', 'CORE', 1, 0, 3),
        ]);
        $this->client->method('countCardsBySetAndFaction')
            ->willReturnCallback(
                static function (string $set, string $faction): int {
                    if ($set !== 'CORE') {
                        return 0;
                    }
                    return match ($faction) {
                        'AX'    => 10, // owned non-zero = 3 → bucket0 = 7
                        'BR'    => 20, // owned non-zero = 4 → bucket0 = 16
                        default => 0,
                    };
                },
            );

        $bySet = $this->service->computePlayset($this->user)['bySet'];
        $core  = $this->findBy($bySet, 'cardSet', 'CORE')['quantities'];

        $this->assertSame(23, $core['0']);  // 7 + 16
        $this->assertSame(3,  $core['1']);  // 2 + 1
        $this->assertSame(1,  $core['2']);  // 1 + 0
        $this->assertSame(3,  $core['3+']); // 0 + 3
    }

    public function testComputePlaysetForwardsUserSetsRaritiesAndCardTypesToCollaborators(): void
    {
        $expectedSets = array_values(array_unique(array_merge(
            CollectionPlaysetService::SETS,
            array_keys(CollectionPlaysetService::SET_ALIASES),
        )));

        $this->viewRepository->expects($this->once())
            ->method('findOwnedCardQuantities')
            ->with(
                $this->user,
                $expectedSets,
                CollectionPlaysetService::RARITIES,
                CollectionPlaysetService::CARD_TYPES,
            )
            ->willReturn([]);

        $this->client->expects($this->atLeastOnce())
            ->method('countCardsBySetAndFaction')
            ->with(
                $this->anything(),
                $this->anything(),
                CollectionPlaysetService::RARITIES,
                CollectionPlaysetService::CARD_TYPES,
            )
            ->willReturn(0);

        $this->service->computePlayset($this->user);
    }
}
