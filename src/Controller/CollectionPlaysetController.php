<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CollectionPlaysetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
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
     * Example: {"byFactionAndSet":[{"faction":"AX","cardSet":"ALIZE","quantities":{"0":23,"1":4,"2":57,"3+":57}}, ...], "byFaction":[...], "bySet":[...]}
     */
    #[Route('/api/collection/playset', name: 'collection_playset', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        return new JsonResponse($this->playsetService->computePlayset($user));
    }
}
