<?php

namespace App\Client;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AlteredCoreClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface      $cache,
        private readonly string              $alteredCoreUrl,
    ) {}

    public function getBaseUrl(): string
    {
        return $this->alteredCoreUrl;
    }

    /**
     * Fetch card data for a list of references from altered-core.
     * Results are cached per reference for 1 hour.
     *
     * @param  string[] $references
     * @param  string   $locale
     * @return array<string, array>  reference => card data
     */
    public function getCardsByReferences(array $references, string $locale = 'fr'): array
    {
        if (empty($references)) {
            return [];
        }

        $missing = [];
        $result  = [];

        foreach ($references as $ref) {
            $cacheKey = 'card_' . md5($ref . '_' . $locale);
            $cached   = $this->cache->get($cacheKey, function (ItemInterface $item) {
                $item->expiresAfter(3600);
                return null;
            });

            if ($cached !== null) {
                $result[$ref] = $cached;
            } else {
                $missing[] = $ref;
            }
        }

        if (empty($missing)) {
            return $result;
        }

        $response = $this->httpClient->request('POST', $this->alteredCoreUrl . '/api/cards/batch', [
            'json'  => ['references' => $missing],
            'query' => ['locale' => $locale],
        ]);

        $cards = $response->toArray();

        foreach ($cards as $card) {
            $ref = $card['reference'] ?? null;
            if (!$ref) {
                continue;
            }

            $result[$ref] = $card;

            $cacheKey = 'card_' . md5($ref . '_' . $locale);
            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, function (ItemInterface $item) use ($card) {
                $item->expiresAfter(3600);
                return $card;
            });
        }

        return $result;
    }

    /**
     * Fetch the full playset "universe" — every standard card that exists in the given sets,
     * restricted to the given rarities and card types — from altered-core, following pagination
     * to completion. Used by {@see \App\Service\CollectionPlaysetCardsService} to list the whole
     * universe card-by-card (including cards the user owns in 0 copies).
     *
     * The cards are returned with their multilingual fields intact (name, imagePath, …) and
     * trimmed to only what the playset/cards endpoint needs, so a single cache entry serves every
     * locale. Cached for 1 hour. Sorted in the site's native order (set date desc, then collector
     * number asc).
     *
     * @param  string[] $sets       canonical set references (no aliases — e.g. CORE, not COREKS)
     * @param  string[] $rarities
     * @param  string[] $cardTypes
     * @return list<array{
     *     reference:string,
     *     collectorNumberFormatedId:?string,
     *     transfuge:bool,
     *     set:array{reference:?string, name:?string, code:?string},
     *     faction:array{code:?string},
     *     rarity:array{reference:?string},
     *     cardType:array{reference:?string},
     *     name:array<string,string>|string|null,
     *     imagePath:array<string,string>|string|null
     * }>
     */
    public function fetchPlaysetUniverse(array $sets, array $rarities, array $cardTypes): array
    {
        if (empty($sets) || empty($rarities) || empty($cardTypes)) {
            return [];
        }

        sort($sets);       // stable cache key regardless of argument order
        sort($rarities);
        sort($cardTypes);
        $cacheKey = 'playset_universe_' . md5(implode(',', $sets) . '|' . implode(',', $rarities) . '|' . implode(',', $cardTypes));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($sets, $rarities, $cardTypes): array {
            $item->expiresAfter(3600);

            $cards        = [];
            $page         = 1;
            $itemsPerPage = 250;

            do {
                $response = $this->httpClient->request('GET', $this->alteredCoreUrl . '/api/cards', [
                    'query' => [
                        'set.reference' => $sets,
                        'rarity'        => $rarities,
                        'cardType'      => $cardTypes,
                        'variation'     => ['standard'],
                        'itemsPerPage'  => $itemsPerPage,
                        'page'          => $page,
                        'order'         => ['set.date' => 'desc', 'collectorNumberFormatedId' => 'asc'],
                    ],
                ]);

                $data    = $response->toArray();
                $members = $data['member'] ?? $data['hydra:member'] ?? [];

                foreach ($members as $card) {
                    if (!is_array($card) || empty($card['reference'])) {
                        continue;
                    }
                    $cards[] = [
                        'reference'                 => $card['reference'],
                        'collectorNumberFormatedId' => $card['collectorNumberFormatedId'] ?? null,
                        'transfuge'                 => (bool) ($card['transfuge'] ?? false),
                        'set'                       => [
                            'reference' => $card['set']['reference'] ?? null,
                            'name'      => $card['set']['name'] ?? null,
                            'code'      => $card['set']['code'] ?? null,
                        ],
                        'faction'  => ['code' => $card['faction']['code'] ?? null],
                        'rarity'   => ['reference' => $card['rarity']['reference'] ?? null],
                        'cardType' => ['reference' => $card['cardType']['reference'] ?? null],
                        'name'      => $card['name'] ?? null,
                        'imagePath' => $card['imagePath'] ?? null,
                    ];
                }

                $lastPage = isset($data['lastPage']) ? (int) $data['lastPage'] : null;
                $page++;
                $hasMore = $lastPage !== null
                    ? $page <= $lastPage
                    : count($members) === $itemsPerPage; // fallback: stop on a short/empty page
            } while ($hasMore);

            return $cards;
        });
    }
}
