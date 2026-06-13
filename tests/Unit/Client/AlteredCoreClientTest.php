<?php

namespace App\Tests\Unit\Client;

use App\Client\AlteredCoreClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AlteredCoreClientTest extends TestCase
{
    private HttpClientInterface $httpClient;
    private CacheInterface $cache;
    private AlteredCoreClient $client;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->cache      = $this->createMock(CacheInterface::class);
        $this->client     = new AlteredCoreClient($this->httpClient, $this->cache, 'https://altered.example.com');
    }

    public function testGetCardsByReferencesReturnsEmptyArrayForEmptyInput(): void
    {
        $this->httpClient->expects($this->never())->method('request');

        $result = $this->client->getCardsByReferences([]);

        $this->assertSame([], $result);
    }

    public function testGetCardsByReferencesFetchesFromApiOnCacheMiss(): void
    {
        $ref      = 'ALT_CORE_B_AX_01_C';
        $cardData = ['reference' => $ref, 'name' => 'Yzmir Stargazer'];

        // Simulate cache miss: always call the provided callback and return its result
        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback): mixed {
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter')->willReturnSelf();
                return $callback($item);
            });
        $this->cache->method('delete')->willReturn(true);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([$cardData]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', 'https://altered.example.com/api/cards/batch', $this->anything())
            ->willReturn($response);

        $result = $this->client->getCardsByReferences([$ref]);

        $this->assertArrayHasKey($ref, $result);
        $this->assertSame($cardData, $result[$ref]);
    }

    public function testGetCardsByReferencesReturnsCachedDataWithoutHttpCall(): void
    {
        $ref        = 'ALT_CORE_B_AX_01_C';
        $cachedCard = ['reference' => $ref, 'name' => 'Cached Card'];

        // Simulate cache hit: return data directly without calling the callback
        $this->cache->method('get')->willReturn($cachedCard);

        $this->httpClient->expects($this->never())->method('request');

        $result = $this->client->getCardsByReferences([$ref]);

        $this->assertSame($cachedCard, $result[$ref]);
    }

    public function testGetCardsByReferencesOnlyFetchesMissingReferences(): void
    {
        $cachedRef  = 'ALT_CORE_B_AX_01_C';
        $missingRef = 'ALT_CORE_B_OR_02_R';
        $cachedCard = ['reference' => $cachedRef, 'name' => 'Cached Card'];
        $fetchedCard = ['reference' => $missingRef, 'name' => 'Fetched Card'];

        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($cachedRef, $cachedCard): mixed {
                // Cache hit for cachedRef, miss for missingRef
                if (str_contains($key, md5($cachedRef . '_fr'))) {
                    return $cachedCard;
                }
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter')->willReturnSelf();
                return $callback($item);
            });
        $this->cache->method('delete')->willReturn(true);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([$fetchedCard]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options) use ($missingRef) {
                return $options['json']['references'] === [$missingRef];
            }))
            ->willReturn($response);

        $result = $this->client->getCardsByReferences([$cachedRef, $missingRef]);

        $this->assertSame($cachedCard, $result[$cachedRef]);
        $this->assertSame($fetchedCard, $result[$missingRef]);
    }

    public function testGetBaseUrlReturnsConfiguredUrl(): void
    {
        $this->assertSame('https://altered.example.com', $this->client->getBaseUrl());
    }

    // ── fetchPlaysetUniverse ─────────────────────────────────────────────────────

    private function mockCacheMiss(): void
    {
        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback): mixed {
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter')->willReturnSelf();
                return $callback($item);
            });
    }

    public function testFetchPlaysetUniverseReturnsEmptyArrayForEmptyArguments(): void
    {
        $this->httpClient->expects($this->never())->method('request');

        $this->assertSame([], $this->client->fetchPlaysetUniverse([], ['COMMON'], ['CHARACTER']));
        $this->assertSame([], $this->client->fetchPlaysetUniverse(['CORE'], [], ['CHARACTER']));
        $this->assertSame([], $this->client->fetchPlaysetUniverse(['CORE'], ['COMMON'], []));
    }

    public function testFetchPlaysetUniverseSendsPerimeterFiltersAndTrimsCards(): void
    {
        $this->mockCacheMiss();

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'member' => [[
                'reference'                 => 'ALT_DUSTER_B_AX_88_C',
                'collectorNumberFormatedId' => 'SDU-002-C-EN',
                'transfuge'                 => false,
                'set'                       => ['name' => 'Seeds of Unity', 'code' => 'SDU', 'reference' => 'DUSTER'],
                'faction'                   => ['id' => 1, 'name' => 'Axiom', 'code' => 'AX', 'position' => 1],
                'rarity'                    => ['id' => 3, 'reference' => 'COMMON'],
                'cardType'                  => ['id' => 2, 'reference' => 'CHARACTER'],
                'name'                      => ['en' => 'Ira', 'fr' => 'Ira'],
                'imagePath'                 => ['en' => 'ira-en.jpg'],
                'mainEffect'                => ['en' => 'huge irrelevant blob'], // must be trimmed away
            ]],
            'totalItems'   => 1,
            'currentPage'  => 1,
            'itemsPerPage' => 250,
            'lastPage'     => 1,
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://altered.example.com/api/cards',
                $this->callback(function (array $options): bool {
                    $q = $options['query'];
                    // The client sorts sets/rarities/cardTypes for a stable cache key; compare unordered.
                    $sets = $q['set.reference']; sort($sets);
                    $rarity = $q['rarity']; sort($rarity);
                    return $sets === ['CORE', 'DUSTER']
                        && $rarity === ['COMMON', 'EXALTED', 'RARE']
                        && $q['cardType'] === ['CHARACTER']
                        && $q['variation'] === ['standard']
                        && $q['page'] === 1
                        && isset($q['order']['set.date'], $q['order']['collectorNumberFormatedId']);
                }),
            )
            ->willReturn($response);

        $universe = $this->client->fetchPlaysetUniverse(['CORE', 'DUSTER'], ['COMMON', 'RARE', 'EXALTED'], ['CHARACTER']);

        $this->assertCount(1, $universe);
        $card = $universe[0];
        $this->assertSame('ALT_DUSTER_B_AX_88_C', $card['reference']);
        $this->assertSame('SDU-002-C-EN', $card['collectorNumberFormatedId']);
        $this->assertFalse($card['transfuge']);
        $this->assertSame('AX', $card['faction']['code']);
        $this->assertSame('DUSTER', $card['set']['reference']);
        $this->assertSame('COMMON', $card['rarity']['reference']);
        $this->assertSame('CHARACTER', $card['cardType']['reference']);
        $this->assertSame(['en' => 'Ira', 'fr' => 'Ira'], $card['name']);
        // Heavy fields are trimmed out of the cached universe.
        $this->assertArrayNotHasKey('mainEffect', $card);
    }

    public function testFetchPlaysetUniverseFollowsPaginationToLastPage(): void
    {
        $this->mockCacheMiss();

        $page1 = $this->createMock(ResponseInterface::class);
        $page1->method('toArray')->willReturn([
            'member'   => [['reference' => 'ALT_CORE_B_AX_01_C', 'faction' => ['code' => 'AX'], 'set' => ['reference' => 'CORE']]],
            'lastPage' => 2,
        ]);
        $page2 = $this->createMock(ResponseInterface::class);
        $page2->method('toArray')->willReturn([
            'member'   => [['reference' => 'ALT_CORE_B_AX_02_R1', 'faction' => ['code' => 'AX'], 'set' => ['reference' => 'CORE']]],
            'lastPage' => 2,
        ]);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($page1, $page2);

        $universe = $this->client->fetchPlaysetUniverse(['CORE'], ['COMMON', 'RARE'], ['CHARACTER']);

        $this->assertSame(
            ['ALT_CORE_B_AX_01_C', 'ALT_CORE_B_AX_02_R1'],
            array_column($universe, 'reference'),
        );
    }

    public function testFetchPlaysetUniverseReturnsCachedValueWithoutHttpCall(): void
    {
        $cached = [['reference' => 'ALT_CORE_B_AX_01_C', 'faction' => ['code' => 'AX'], 'set' => ['reference' => 'CORE']]];
        $this->cache->method('get')->willReturn($cached);
        $this->httpClient->expects($this->never())->method('request');

        $this->assertSame($cached, $this->client->fetchPlaysetUniverse(['CORE'], ['COMMON'], ['CHARACTER']));
    }
}
