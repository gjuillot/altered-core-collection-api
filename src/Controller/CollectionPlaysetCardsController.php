<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CollectionPlaysetCardsService;
use App\Service\CollectionPlaysetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CollectionPlaysetCardsController extends AbstractController
{
    public function __construct(
        private readonly Security                      $security,
        private readonly CollectionPlaysetCardsService $playsetCardsService,
    ) {}

    /**
     * GET /api/collection/playset/cards
     *
     * Lists the whole playset universe (the COMMON/RARE/EXALTED CHARACTER/SPELL/PERMANENT cards of
     * the supported sets) card by card for the connected user, including cards owned in 0 copies.
     * Each card carries its versions (C / R1 / R2 / E) with the per-version owned count.
     *
     * Query params (all optional, combinable): locale, cardSet[], faction[], cardType[], rarity[],
     * name, copies[], page, itemsPerPage. Response: a paginated envelope
     * {items, page, itemsPerPage, totalItems, totalPages}.
     */
    #[Route('/api/collection/playset/cards', name: 'collection_playset_cards', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $rarityFilter = $request->query->all('rarity');
        $copiesFilter = $request->query->all('copies');

        if ($invalid = array_diff($rarityFilter, CollectionPlaysetService::RARITIES)) {
            return $this->badRequest(sprintf(
                'Invalid rarity value(s): %s. Allowed: %s.',
                implode(', ', $invalid),
                implode(', ', CollectionPlaysetService::RARITIES),
            ));
        }
        if ($invalid = array_diff($copiesFilter, CollectionPlaysetCardsService::COPIES_BUCKETS)) {
            return $this->badRequest(sprintf(
                'Invalid copies value(s): %s. Allowed: %s.',
                implode(', ', $invalid),
                implode(', ', CollectionPlaysetCardsService::COPIES_BUCKETS),
            ));
        }

        $name = $request->query->get('name');

        $result = $this->playsetCardsService->listCards(
            user:           $user,
            locale:         (string) $request->query->get('locale', 'en'),
            cardSetFilter:  $request->query->all('cardSet'),
            factionFilter:  $request->query->all('faction'),
            cardTypeFilter: $request->query->all('cardType'),
            rarityFilter:   $rarityFilter,
            nameFilter:     $name !== null ? (string) $name : null,
            copiesFilter:   $copiesFilter,
            page:           $request->query->getInt('page', 1),
            itemsPerPage:   $request->query->getInt('itemsPerPage', CollectionPlaysetCardsService::DEFAULT_ITEMS_PER_PAGE),
        );

        return new JsonResponse($result);
    }

    private function badRequest(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_BAD_REQUEST);
    }
}
