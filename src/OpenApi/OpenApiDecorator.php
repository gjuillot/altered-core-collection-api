<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\Model\SecurityScheme;
use ApiPlatform\OpenApi\OpenApi;

/**
 * Ajoute à la documentation OpenAPI les endpoints gérés par des contrôleurs
 * Symfony manuels (invisibles pour API Platform) :
 *
 *   GET    /api/collection/playset
 *   POST   /api/collection/batch
 *   PATCH  /api/collection/batch
 *   DELETE /api/collection/batch
 *
 * Déclare également le security scheme Bearer JWT, absent quand un
 * authenticateur Symfony custom est utilisé à la place d'un guard API Platform.
 */
final class OpenApiDecorator implements OpenApiFactoryInterface
{
    public function __construct(private readonly OpenApiFactoryInterface $decorated) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $openApi = $this->addBearerSecurityScheme($openApi);
        $this->addPlaysetPath($openApi);
        $this->addBatchPath($openApi);

        return $openApi;
    }

    // -------------------------------------------------------------------------
    // Security scheme
    // -------------------------------------------------------------------------

    /**
     * Components est immutable (withSecuritySchemes retourne un clone) :
     * on doit rebrancher la chaîne et retourner le nouvel OpenApi.
     * Paths, en revanche, est mutable — addPath() modifie en place.
     */
    private function addBearerSecurityScheme(OpenApi $openApi): OpenApi
    {
        $securitySchemes = $openApi->getComponents()->getSecuritySchemes() ?? new \ArrayObject();

        $securitySchemes['bearerAuth'] = new SecurityScheme(
            type:         'http',
            description:  'JWT obtenu via Keycloak (prod) ou POST /api/dev/auth (dev).',
            scheme:       'bearer',
            bearerFormat: 'JWT',
        );

        return $openApi->withComponents(
            $openApi->getComponents()->withSecuritySchemes($securitySchemes)
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/collection/playset
    // -------------------------------------------------------------------------

    private function addPlaysetPath(OpenApi $openApi): void
    {
        $quantities = new \ArrayObject([
            'type'       => 'object',
            'properties' => [
                '0'  => ['type' => 'integer', 'description' => 'Références non possédées'],
                '1'  => ['type' => 'integer', 'description' => 'Possédées en quantité exacte 1'],
                '2'  => ['type' => 'integer', 'description' => 'Possédées en quantité exacte 2'],
                '3+' => ['type' => 'integer', 'description' => 'Possédées en quantité ≥ 3'],
            ],
        ]);

        $responseSchema = new \ArrayObject([
            'type'       => 'object',
            'properties' => [
                'byFactionAndSet' => [
                    'type'  => 'array',
                    'items' => new \ArrayObject([
                        'type'       => 'object',
                        'properties' => [
                            'faction'    => ['type' => 'string', 'example' => 'AX'],
                            'cardSet'    => ['type' => 'string', 'example' => 'CORE'],
                            'quantities' => $quantities,
                        ],
                    ]),
                ],
                'byFaction' => [
                    'type'  => 'array',
                    'items' => new \ArrayObject([
                        'type'       => 'object',
                        'properties' => [
                            'faction'    => ['type' => 'string', 'example' => 'AX'],
                            'quantities' => $quantities,
                        ],
                    ]),
                ],
                'bySet' => [
                    'type'  => 'array',
                    'items' => new \ArrayObject([
                        'type'       => 'object',
                        'properties' => [
                            'cardSet'    => ['type' => 'string', 'example' => 'CORE'],
                            'quantities' => $quantities,
                        ],
                    ]),
                ],
            ],
        ]);

        $openApi->getPaths()->addPath('/api/collection/playset', new PathItem(
            get: new Operation(
                operationId: 'getCollectionPlayset',
                tags:        ['Collection'],
                responses:   [
                    200 => new Response(
                        description: 'Complétion du playset par faction × set × bucket de quantité, avec agrégats.',
                        content: new \ArrayObject([
                            'application/json' => new MediaType(schema: $responseSchema),
                        ]),
                    ),
                    401 => new Response(description: 'Non authentifié.'),
                ],
                summary:     'Statistiques de complétion du playset.',
                description: "Pour chaque combinaison faction × set, retourne le nombre de références dans chaque bucket de quantité (0 = non possédé, 1, 2, 3+).\n\nLes éditions CORE et COREKS contiennent les mêmes cartes et sont fusionnées sous un seul set « CORE » : une carte possédée dans les deux éditions est comptée une seule fois avec la somme des quantités (ex. 1×COREKS + 2×CORE = une carte ×3, bucket « 3+ »).\n\nSeules les raretés COMMON / RARE / EXALTED et les types CHARACTER / SPELL / PERMANENT / LANDMARK_PERMANENT / EXPEDITION_PERMANENT sont pris en compte. Les héros et les raretés UNIQUE sont exclus.",
                security:    [['bearerAuth' => []]],
            ),
        ));
    }

    // -------------------------------------------------------------------------
    // POST / PATCH / DELETE /api/collection/batch
    // -------------------------------------------------------------------------

    private function addBatchPath(OpenApi $openApi): void
    {
        $security = [['bearerAuth' => []]];
        $tags     = ['Collection'];
        $err401   = new Response(description: 'Non authentifié.');
        $err422   = new Response(description: 'Données invalides (format cardReference, quantité hors plage, etc.).');

        // ── POST ──────────────────────────────────────────────────────────────
        $cardInput = new \ArrayObject([
            'type'       => 'object',
            'required'   => ['cardReference'],
            'properties' => [
                'cardReference' => ['type' => 'string', 'example' => 'ALT_CORE_B_AX_08_C'],
                'quantity'      => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99, 'default' => 1],
                'isFoil'        => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        $postOp = new Operation(
            operationId: 'importCollectionBatch',
            tags:        $tags,
            responses:   [
                201 => new Response(
                    description: 'Cartes créées et références ignorées (déjà présentes).',
                    content: new \ArrayObject([
                        'application/json' => new MediaType(schema: new \ArrayObject([
                            'type'       => 'object',
                            'properties' => [
                                'created' => [
                                    'type'  => 'array',
                                    'items' => ['$ref' => '#/components/schemas/Collection-collection.read'],
                                ],
                                'skipped' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ])),
                    ]),
                ),
                401 => $err401,
                422 => $err422,
            ],
            summary:     'Import de cartes en masse (max 100).',
            description: 'Crée jusqu\'à 100 cartes en une seule requête. Les métadonnées (faction, rareté, nom…) sont récupérées depuis l\'API Altered. Les références déjà présentes dans la collection sont ignorées sans erreur.',
            requestBody: new RequestBody(
                description: 'Tableau de cartes à importer.',
                content: new \ArrayObject([
                    'application/json' => new MediaType(schema: new \ArrayObject([
                        'type'       => 'object',
                        'required'   => ['cards'],
                        'properties' => [
                            'cards' => ['type' => 'array', 'items' => $cardInput, 'maxItems' => 100],
                        ],
                    ])),
                ]),
                required: true,
            ),
            security: $security,
        );

        // ── PATCH ─────────────────────────────────────────────────────────────
        $updateInput = new \ArrayObject([
            'type'       => 'object',
            'required'   => ['id'],
            'properties' => [
                'id'       => ['type' => 'integer'],
                'quantity' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99],
                'isFoil'   => ['type' => 'boolean'],
            ],
        ]);

        $patchOp = new Operation(
            operationId: 'updateCollectionBatch',
            tags:        $tags,
            responses:   [
                200 => new Response(
                    description: 'Nombre de cartes effectivement mises à jour.',
                    content: new \ArrayObject([
                        'application/json' => new MediaType(schema: new \ArrayObject([
                            'type'       => 'object',
                            'properties' => ['updated' => ['type' => 'integer']],
                        ])),
                    ]),
                ),
                401 => $err401,
                422 => $err422,
            ],
            summary:     'Mise à jour en masse (quantity / isFoil, max 100).',
            description: 'Met à jour quantity et/ou isFoil sur jusqu\'à 100 cartes. Les IDs inconnus ou appartenant à un autre utilisateur sont silencieusement ignorés.',
            requestBody: new RequestBody(
                description: 'Tableau de mises à jour.',
                content: new \ArrayObject([
                    'application/json' => new MediaType(schema: new \ArrayObject([
                        'type'       => 'object',
                        'required'   => ['updates'],
                        'properties' => [
                            'updates' => ['type' => 'array', 'items' => $updateInput, 'maxItems' => 100],
                        ],
                    ])),
                ]),
                required: true,
            ),
            security: $security,
        );

        // ── DELETE ────────────────────────────────────────────────────────────
        $deleteOp = new Operation(
            operationId: 'deleteCollectionBatch',
            tags:        $tags,
            responses:   [
                200 => new Response(
                    description: 'Nombre de cartes supprimées.',
                    content: new \ArrayObject([
                        'application/json' => new MediaType(schema: new \ArrayObject([
                            'type'       => 'object',
                            'properties' => ['deleted' => ['type' => 'integer']],
                        ])),
                    ]),
                ),
                401 => $err401,
            ],
            summary:     'Suppression en masse de cartes (max 100).',
            description: 'Supprime jusqu\'à 100 cartes de la collection. Les IDs inconnus ou appartenant à un autre utilisateur sont silencieusement ignorés.',
            requestBody: new RequestBody(
                description: 'Liste d\'IDs à supprimer.',
                content: new \ArrayObject([
                    'application/json' => new MediaType(schema: new \ArrayObject([
                        'type'       => 'object',
                        'required'   => ['ids'],
                        'properties' => [
                            'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 100],
                        ],
                    ])),
                ]),
                required: true,
            ),
            security: $security,
        );

        $openApi->getPaths()->addPath('/api/collection/batch', new PathItem(
            post:   $postOp,
            patch:  $patchOp,
            delete: $deleteOp,
        ));
    }
}
