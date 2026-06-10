<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CollectionPlaysetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CollectionPlaysetController extends AbstractController
{
    public function __construct(
        private readonly Security                 $security,
        private readonly CollectionPlaysetService $playsetService,
    ) {}

    /**
     * GET /api/collection/playset
     *
     * For the connected user, returns the number of unique cardReferences in each
     * faction × cardSet × quantity-bucket (0, 1, 2, 3+) across the supported sets,
     * plus per-faction and per-set aggregations. Counts only; no localized data.
     *
     * An optional repeatable `rarity[]` query parameter narrows the computation to a subset of
     * the supported rarities ({@see CollectionPlaysetService::RARITIES}); when omitted, all of
     * them are counted. Unknown values are rejected with a 422.
     *
     * Example: {"byFactionAndSet":[{"faction":"AX","cardSet":"ALIZE","quantities":{"0":23,"1":4,"2":57,"3+":57}}, ...], "byFaction":[...], "bySet":[...]}
     */
    #[Route('/api/collection/playset', name: 'collection_playset', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $rarities = $request->query->all('rarity');
        if ($rarities === []) {
            $rarities = null;
        } else {
            $rarities = array_values(array_unique($rarities));
            $invalid  = array_diff($rarities, CollectionPlaysetService::RARITIES);
            if ($invalid !== []) {
                return new JsonResponse(
                    [
                        'error' => sprintf(
                            'Unsupported rarity: %s. Allowed values: %s.',
                            implode(', ', $invalid),
                            implode(', ', CollectionPlaysetService::RARITIES),
                        ),
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        return new JsonResponse($this->playsetService->computePlayset($user, $rarities));
    }
}
