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

    public function testComputePlaysetReturnsTheThreeViews(): void
    {
        $this->viewRepository->method('countOwnedBucketsByFactionAndSet')->willReturn([]);
        $this->client->method('countCardsBySetAndFaction')->willReturn(0);

        $result = $this->service->computePlayset($this->user);

        $this->assertSame(['byFactionAndSet', 'byFaction', 'bySet'], array_keys($result));
        $this->assertCount(count(CollectionPlaysetService::FACTIONS), $result['byFaction']);
        $this->assertCount(count(CollectionPlaysetService::SETS), $result['bySet']);
    }

    public function testComputePlaysetEmitsTheFullGrid(): void
    {
        $this->viewRepository->method('countOwnedBucketsByFactionAndSet')->willReturn([]);
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

    public function testComputePlaysetBucketsOwnedAndDerivesZeroFromUniverse(): void
    {
        $this->viewRepository->method('countOwnedBucketsByFactionAndSet')->willReturn([
            'AX|ALIZE' => ['1' => 4, '2' => 57, '3+' => 57],
        ]);
        // universe = 23 (not owned) + 118 (owned non-zero) = 141 for AX/ALIZE; 0 elsewhere
        $this->client->method('countCardsBySetAndFaction')
            ->willReturnCallback(
                static fn (string $set, string $faction, array $rarities, array $cardTypes, string $locale = 'fr'): int =>
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
        $this->viewRepository->method('countOwnedBucketsByFactionAndSet')->willReturn([
            'BR|CORE' => ['1' => 2, '2' => 3, '3+' => 5],
        ]);
        $this->client->method('countCardsBySetAndFaction')
            ->willReturnCallback(
                static fn (string $set, string $faction, array $rarities, array $cardTypes, string $locale = 'fr'): int =>
                    ($set === 'CORE' && $faction === 'BR') ? 10 : 0,
            );

        $grid = $this->service->computePlayset($this->user)['byFactionAndSet'];
        $q    = $this->findCombo($grid, 'BR', 'CORE')['quantities'];

        $this->assertSame(0, $q['0']);
    }

    public function testComputePlaysetClampsZeroBucketAtZeroOnDataDrift(): void
    {
        // User owns more references than altered-core reports as the universe.
        $this->viewRepository->method('countOwnedBucketsByFactionAndSet')->willReturn([
            'YZ|EOLE' => ['1' => 5, '2' => 0, '3+' => 0],
        ]);
        $this->client->method('countCardsBySetAndFaction')->willReturn(3);

        $grid = $this->service->computePlayset($this->user)['byFactionAndSet'];
        $q    = $this->findCombo($grid, 'YZ', 'EOLE')['quantities'];

        $this->assertSame(0, $q['0']);
    }

    public function testComputePlaysetByFactionSumsAcrossAllSets(): void
    {
        // AX owns cards in two different sets; the per-faction total must sum both.
        $this->viewRepository->method('countOwnedBucketsByFactionAndSet')->willReturn([
            'AX|CORE'  => ['1' => 2, '2' => 1, '3+' => 0],
            'AX|ALIZE' => ['1' => 3, '2' => 0, '3+' => 4],
        ]);
        $this->client->method('countCardsBySetAndFaction')
            ->willReturnCallback(
                static function (string $set, string $faction, array $rarities, array $cardTypes, string $locale = 'fr'): int {
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

        // 0: 7 + 13 (+ zeros from the other five sets) = 20
        $this->assertSame(20, $ax['0']);
        $this->assertSame(5,  $ax['1']);  // 2 + 3
        $this->assertSame(1,  $ax['2']);  // 1 + 0
        $this->assertSame(4,  $ax['3+']); // 0 + 4
    }

    public function testComputePlaysetBySetSumsAcrossAllFactions(): void
    {
        // Two factions own cards in CORE; the per-set total must sum both.
        $this->viewRepository->method('countOwnedBucketsByFactionAndSet')->willReturn([
            'AX|CORE' => ['1' => 2, '2' => 1, '3+' => 0],
            'BR|CORE' => ['1' => 1, '2' => 0, '3+' => 3],
        ]);
        $this->client->method('countCardsBySetAndFaction')
            ->willReturnCallback(
                static function (string $set, string $faction, array $rarities, array $cardTypes, string $locale = 'fr'): int {
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

    public function testComputePlaysetForwardsUserSetsRaritiesCardTypesAndLocaleToCollaborators(): void
    {
        $this->viewRepository->expects($this->once())
            ->method('countOwnedBucketsByFactionAndSet')
            ->with(
                $this->user,
                CollectionPlaysetService::SETS,
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
                'en',
            )
            ->willReturn(0);

        $this->service->computePlayset($this->user, 'en');
    }
}
