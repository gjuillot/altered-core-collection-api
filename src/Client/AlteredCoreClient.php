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
     * Count the cards (= distinct references) that exist in a given set, for a given faction,
     * restricted to the given rarities and card types. Used to size the "not owned" bucket of
     * the collection statistics. Cached for 1 hour.
     *
     * We only need the total, not the cards themselves, so we request `itemsPerPage=1` and read
     * the collection-wide `totalItems` (authoritative across all pages). The endpoint requires
     * the rarity filter to be supplied. Falls back to counting distinct references in the member
     * list if no total field is present.
     *
     * @param string[] $rarities
     * @param string[] $cardTypes
     */
    public function countCardsBySetAndFaction(string $set, string $faction, array $rarities, array $cardTypes, string $locale = 'fr'): int
    {
        sort($rarities);  // stable cache key regardless of argument order
        sort($cardTypes);
        $cacheKey = 'card_count_' . md5($set . '_' . $faction . '_' . implode(',', $rarities) . '_' . implode(',', $cardTypes) . '_' . $locale);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($set, $faction, $rarities, $cardTypes, $locale): int {
            $item->expiresAfter(3600);

            $response = $this->httpClient->request('GET', $this->alteredCoreUrl . '/api/cards', [
                'query' => [
                    'set.reference' => [$set],
                    'faction.code'  => [$faction],
                    'rarity'        => $rarities,
                    'cardType'      => $cardTypes,
                    'itemsPerPage'  => 1,
                    'locale'        => $locale,
                ],
            ]);

            $data = $response->toArray();

            if (isset($data['totalItems'])) {
                return (int) $data['totalItems'];
            }
            if (isset($data['hydra:totalItems'])) {
                return (int) $data['hydra:totalItems'];
            }

            $cards = $data['member'] ?? $data['hydra:member'] ?? $data;
            if (!is_array($cards)) {
                return 0;
            }

            $refs = array_filter(array_map(
                static fn($card) => is_array($card) ? ($card['reference'] ?? null) : null,
                $cards,
            ));

            return count(array_unique($refs));
        });
    }
}
