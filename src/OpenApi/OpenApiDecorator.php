<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
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
 *   GET    /api/collection/playset/cards
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
        $this->addPlaysetCardsPath($openApi);
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
                    422 => new Response(description: 'Rareté inconnue (valeurs autorisées : COMMON, RARE, EXALTED).'),
                ],
                summary:     'Statistiques de complétion du playset.',
                description: "Pour chaque combinaison faction × set, retourne le nombre de références dans chaque bucket de quantité (0 = non possédé, 1, 2, 3+).\n\nLes éditions CORE et COREKS contiennent les mêmes cartes et sont fusionnées sous un seul set « CORE » : une carte possédée dans les deux éditions est comptée une seule fois avec la somme des quantités (ex. 1×COREKS + 2×CORE = une carte ×3, bucket « 3+ »). De même, les réimpressions d'une carte (cardProducts A/P alt-art ou promo) sont repliées sur sa version booster (B), côté univers comme côté possédé — cohérent avec GET /api/collection/playset/cards.\n\nSeules les raretés COMMON / RARE / EXALTED et les types CHARACTER / SPELL / PERMANENT / LANDMARK_PERMANENT / EXPEDITION_PERMANENT sont pris en compte. Les héros et les raretés UNIQUE sont exclus. Le paramètre `rarity[]` permet de restreindre le calcul à un sous-ensemble de ces raretés ; sans lui, les trois sont comptabilisées.",
                parameters:  [
                    new Parameter(
                        name:        'rarity[]',
                        in:          'query',
                        description: 'Restreint le calcul à un sous-ensemble de raretés. Répétable : ?rarity[]=COMMON&rarity[]=RARE. Si absent, les trois raretés sont comptabilisées.',
                        required:    false,
                        schema:      ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['COMMON', 'RARE', 'EXALTED']]],
                        explode:     true,
                    ),
                ],
                security:    [['bearerAuth' => []]],
            ),
        ));
    }

    // -------------------------------------------------------------------------
    // GET /api/collection/playset/cards
    // -------------------------------------------------------------------------

    private function addPlaysetCardsPath(OpenApi $openApi): void
    {
        $version = new \ArrayObject([
            'type'       => 'object',
            'properties' => [
                'reference'                 => ['type' => 'string', 'example' => 'ALT_DUSTER_B_AX_88_R2'],
                'collectorNumberFormatedId' => ['type' => 'string', 'nullable' => true, 'example' => 'SDU-002-F-EN'],
                'faction'                   => ['type' => 'string', 'description' => 'Code de la faction réelle de la version (une R2 transfuge porte sa faction d\'accueil).', 'example' => 'BR'],
                'rarity'                    => ['type' => 'string', 'enum' => ['COMMON', 'RARE', 'EXALTED'], 'example' => 'RARE'],
                'transfuge'                 => ['type' => 'boolean'],
                'owned'                     => ['type' => 'integer', 'description' => 'Total possédé pour cette version, brut et non plafonné (foil + non-foil et tous les cardProducts confondus).', 'example' => 2],
                'imagePath'                 => ['type' => 'string', 'nullable' => true],
                'ownedCardProducts'         => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string', 'example' => 'A'],
                    'description' => 'Présent uniquement quand la version existe en plusieurs cardProducts : la liste des produits effectivement possédés par le joueur (ex. ["A","B"]).',
                ],
            ],
        ]);

        $item = new \ArrayObject([
            'type'       => 'object',
            'properties' => [
                'baseReference' => ['type' => 'string', 'description' => 'Référence sans le suffixe de rareté ni le produit (normalisé en B).', 'example' => 'ALT_DUSTER_B_AX_88'],
                'name'          => ['type' => 'string', 'nullable' => true, 'example' => 'Ira, Fair Attendee'],
                'cardSet'       => ['type' => 'string', 'example' => 'DUSTER'],
                'cardType'      => ['type' => 'string', 'example' => 'CHARACTER'],
                'versions'      => ['type' => 'array', 'items' => $version],
            ],
        ]);

        $responseSchema = new \ArrayObject([
            'type'       => 'object',
            'properties' => [
                'summary' => new \ArrayObject([
                    'type'        => 'object',
                    'description' => 'Totaux sur l\'ensemble filtré (toutes pages confondues), pas seulement la page courante.',
                    'properties'  => [
                        'totalCards'    => ['type' => 'integer', 'description' => 'Nombre de cartes correspondant aux filtres.', 'example' => 587],
                        'totalVersions' => ['type' => 'integer', 'description' => 'Nombre de versions cumulées sur ces cartes.', 'example' => 1542],
                        'totalOwned'    => ['type' => 'integer', 'description' => 'Nombre total d\'EXEMPLAIRES possédés (somme des owned de toutes ces versions, pas un comptage de versions).', 'example' => 768],
                        'ownedBuckets'  => new \ArrayObject([
                            'type'        => 'object',
                            'description' => 'Répartition des versions par palier d\'owned (mêmes paliers que copies[]). Somme = totalVersions.',
                            'properties'  => [
                                '0'     => ['type' => 'integer', 'description' => 'Versions possédées en 0 exemplaire.', 'example' => 1230],
                                '1-2'   => ['type' => 'integer', 'description' => 'Versions possédées en 1 ou 2 exemplaires.', 'example' => 200],
                                '3'     => ['type' => 'integer', 'description' => 'Versions possédées en exactement 3 exemplaires.', 'example' => 80],
                                '4plus' => ['type' => 'integer', 'description' => 'Versions possédées en 4 exemplaires ou plus.', 'example' => 32],
                            ],
                        ]),
                    ],
                ]),
                'items'        => ['type' => 'array', 'items' => $item],
                'page'         => ['type' => 'integer', 'example' => 1],
                'itemsPerPage' => ['type' => 'integer', 'example' => 30],
                'totalItems'   => ['type' => 'integer', 'description' => 'Nombre total de CARTES correspondant aux filtres (pas de versions). Égal à summary.totalCards.', 'example' => 587],
                'totalPages'   => ['type' => 'integer', 'example' => 20],
            ],
        ]);

        $arrayParam = static fn (string $name, string $description, ?array $enum = null): Parameter => new Parameter(
            name:        $name,
            in:          'query',
            description: $description,
            required:    false,
            schema:      ['type' => 'array', 'items' => $enum !== null ? ['type' => 'string', 'enum' => $enum] : ['type' => 'string']],
            style:       'form',
            explode:     true,
        );

        $parameters = [
            new Parameter(name: 'locale', in: 'query', description: 'Locale du nom localisé (défaut "en").', required: false, schema: ['type' => 'string', 'default' => 'en']),
            $arrayParam('cardSet[]',  'Filtre par référence de set (niveau carte), ex. DUSTER.'),
            $arrayParam('faction[]',  'Filtre par faction réelle de la version, ex. AX.'),
            $arrayParam('cardType[]', 'Filtre par type (niveau carte).', ['CHARACTER', 'SPELL', 'PERMANENT']),
            $arrayParam('rarity[]',   'Filtre de périmètre sur les versions (RARE garde R1 et R2). Omission = tout le périmètre.', ['COMMON', 'RARE', 'EXALTED']),
            new Parameter(name: 'name', in: 'query', description: 'Recherche partielle insensible à la casse sur le nom (dans la locale).', required: false, schema: ['type' => 'string']),
            $arrayParam('copies[]',   'Filtre de périmètre sur les versions : ne garde que les versions dont l\'owned tombe dans un bucket sélectionné. Une carte est masquée si aucune version ne survit.', ['0', '1-2', '3', '4plus']),
            new Parameter(name: 'page', in: 'query', description: 'Numéro de page (défaut 1).', required: false, schema: ['type' => 'integer', 'default' => 1, 'minimum' => 1]),
            new Parameter(name: 'itemsPerPage', in: 'query', description: 'Nombre de cartes par page (défaut 30, max 100).', required: false, schema: ['type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 100]),
        ];

        $openApi->getPaths()->addPath('/api/collection/playset/cards', new PathItem(
            get: new Operation(
                operationId: 'getCollectionPlaysetCards',
                tags:        ['Collection'],
                parameters:  $parameters,
                responses:   [
                    200 => new Response(
                        description: 'Univers playset listé carte par carte (cartes possédées en 0 exemplaire incluses), avec enveloppe de pagination.',
                        content: new \ArrayObject([
                            'application/json' => new MediaType(schema: $responseSchema),
                        ]),
                    ),
                    400 => new Response(description: 'Valeur invalide pour rarity[] ou copies[].'),
                    401 => new Response(description: 'Non authentifié.'),
                ],
                summary:     'Univers playset carte par carte (liste de courses).',
                description: "Liste TOUT l'univers playset carte par carte, y compris les cartes possédées en 0 exemplaire (ce que GET /api/collection ne permet pas). Alimente la vue « liste de courses ».\n\nUne carte regroupe ses versions (C, R1, R2, E, dans cet ordre). `owned` est la quantité brute non plafonnée possédée pour la version, foil + non-foil et tous les cardProducts confondus : les éditions COREKS et les réimpressions A/P sont repliées sur la version B canonique. Quand une version existe en plusieurs cardProducts, elle expose `ownedCardProducts`.\n\nMêmes raretés et types que GET /api/collection/playset. Les champs reprennent le format aplati de /api/collection (chaînes mono-locale, faction/rareté/type en codes).",
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
