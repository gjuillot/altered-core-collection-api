# Altered Collection — Community API

> [Version française ci-dessous](#altered-collection--api-communautaire)

Open-source REST API for managing the **personal card collection** of [Altered](https://altered.gg) players, built with Symfony 8, API Platform and PostgreSQL.
Each authenticated user (via Keycloak) can add, update, and delete cards from their collection, with quantities and foil variants.

Card metadata (name, faction, rarity, costs, image, etc.) is **not** stored locally: it is fetched on the fly from the [altered-core-cards-api](https://github.com/Altered-Community/altered-core-cards-api) and cached in a read view.

---

## Tech stack

- **PHP 8.4** + **Symfony 8**
- **API Platform 4**
- **PostgreSQL 16**
- **Doctrine ORM 3** + migrations
- **Keycloak** (JWT authentication, validated via JWKS — `firebase/php-jwt`)
- **nelmio/cors-bundle** (CORS)
- **Twig** (home page)
- **Docker** (PostgreSQL via Symfony CLI)

---

## Architecture

The API uses a light read/write separation (lightweight CQRS):

- **`CollectionCard`** — write model. Source of truth: `cardReference`, `quantity`, `isFoil`, linked to a `User`.
- **`CollectionCardView`** — read model exposed by the API. Combines write fields **and** card metadata (faction, rarity, costs, image, sub-types…) fetched from altered-core at write time. Filters operate on this view.

On each creation, metadata is fetched via `AlteredCoreClient` (1h cache per reference) and frozen in the view. The `collection:rebuild-views` command allows rebuilding / refreshing all views.

---

## Installation

### Requirements

- PHP 8.4
- Composer
- Symfony CLI
- Docker (for PostgreSQL)
- A running **altered-core-cards-api** instance (card catalogue)
- A **Keycloak** instance (unless using dev auth mode, see below)

### 1. Clone the project

```bash
git clone <repo-url>
cd altered-core-collection-api
```

### 2. Install dependencies

```bash
composer install
```

### 3. Configure the environment

```bash
cp .env .env.local
```

Edit `.env.local` with your configuration:

```env
# Database
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/altered_collection?serverVersion=16&charset=utf8"

# Keycloak
KEYCLOAK_BASE_URL=http://localhost:8080
KEYCLOAK_REALM=altered
KEYCLOAK_CLIENT_ID=altered-collection
KEYCLOAK_CLIENT_SECRET=

# Dev authentication (mint local tokens without Keycloak)
DEV_AUTH_ENABLED=false

# altered-core API (card catalogue)
ALTERED_CORE_URL=http://localhost:41309
```

### 4. Start the database

```bash
symfony server:start
```

Symfony CLI automatically detects `compose.yaml` and starts PostgreSQL via Docker (database `altered_collection`, user `app`).

### 5. Create the database and run migrations

```bash
symfony console doctrine:database:create
symfony console doctrine:migrations:migrate
```

---

## Authentication

The API is **stateless** and protected by JWT token. All routes under `/api` (except `/api/dev/auth` and `/api/docs`) require a header:

```
Authorization: Bearer <token>
```

### Keycloak (production)

The token is validated against the Keycloak realm's public keys (JWKS). On the first request, a local `User` is automatically created from the `sub` claim (and `email` / `preferred_username`).

### Dev mode

Setting `DEV_AUTH_ENABLED=true` enables a route to generate a locally signed token (HS256 via `APP_SECRET`), without Keycloak:

```bash
POST /api/dev/auth
Content-Type: application/json

{ "sub": "dev-user-1", "email": "dev@example.com", "username": "dev-user" }
```

Response: `{ "token": "...", "expires_in": 3600, "payload": { ... } }`

---

## API

Interactive documentation (Swagger UI) is available at:

```
http://localhost/api/docs
```

### Collection endpoints

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/collection` | Current user's collection (filterable) |
| GET | `/api/collection/{id}` | A single collection entry by ID |
| POST | `/api/collection` | Add a card to the collection |
| PATCH | `/api/collection/{id}` | Update an entry (`quantity`, `isFoil`) |
| DELETE | `/api/collection/{id}` | Delete an entry |

> PATCH uses content-type `application/merge-patch+json`.
> Each user can only see and modify **their own** collection.

### Batch endpoints

For bulk operations (max **100** items per request):

| Method | Endpoint | Body | Response |
|---|---|---|---|
| POST | `/api/collection/batch` | `{"cards": [{"cardReference","quantity","isFoil"}, ...]}` | `{"created": [...], "skipped": [...]}` |
| PATCH | `/api/collection/batch` | `{"updates": [{"id","quantity","isFoil"}, ...]}` | `{"updated": N}` |
| DELETE | `/api/collection/batch` | `{"ids": [1, 2, 3]}` | `{"deleted": N}` |

> POST batch silently skips cards already present (`skipped`) and fetches all metadata in a single call to altered-core.
> PATCH / DELETE batch silently ignore IDs that do not exist or belong to another user.

### Playset endpoints

Read-only views for tracking **playset completion**. Only COMMON / RARE / EXALTED rarities and CHARACTER / SPELL / PERMANENT (incl. LANDMARK_PERMANENT / EXPEDITION_PERMANENT) types are considered; heroes and UNIQUE are excluded. CORE and COREKS share the same cards and are merged into a single CORE set, and a card's products (B booster, A/P alt-art / promo) are folded onto the B reference — both endpoints apply the same merge.

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/collection/playset` | Completion stats per faction × set × quantity bucket (0, 1, 2, 3+), plus per-faction and per-set aggregates. |
| GET | `/api/collection/playset/cards` | The whole playset universe, card by card — including cards owned in 0 copies (the "shopping list" view). Paginated. |

#### `GET /api/collection/playset/cards`

Lists every playset card grouped by base reference, each with its versions (C, R1, R2, E) and a per-version `owned` count. `owned` is **raw and uncapped** (foil + non-foil and every card product summed): COREKS copies and A/P alt-art / promo printings are folded onto the canonical **B** version. Cards owned in 0 copies are included.

Query parameters (all optional, combinable):

| Param | Description |
|---|---|
| `locale` | Locale for the localized `name` (default `en`). |
| `cardSet[]` | Filter by set reference (card level), e.g. `cardSet[]=DUSTER`. |
| `faction[]` | Filter by the version's **real** faction, e.g. `faction[]=AX` (a transfuge R2 is matched on its host faction, e.g. Bravos). |
| `cardType[]` | Filter by card type (card level): `CHARACTER`, `SPELL`, `PERMANENT`. |
| `rarity[]` | Keep only these version rarities: `COMMON`, `RARE` (keeps both R1 and R2), `EXALTED`. Omitted = whole perimeter. |
| `name` | Case-insensitive partial match on the localized name. |
| `copies[]` | Keep cards with at least one version owned in a bucket: `0`, `1-2`, `3`, `4plus`. |
| `page` | Page number (default 1). |
| `itemsPerPage` | Cards per page (default 30, max 100). |

```jsonc
{
  "summary": {
    "totalCards": 587,        // cards matching the filters
    "totalVersions": 1542,    // versions cumulated across those cards
    "totalOwned": 768,        // total COPIES owned (sum of every version's owned, NOT a version count)
    "ownedBuckets": { "0": 1230, "1-2": 200, "3": 80, "4plus": 32 } // versions per owned tier; sum = totalVersions (1542)
  }, // totals over the whole filtered result, all pages
  "items": [
    {
      "baseReference": "ALT_DUSTER_B_AX_88",
      "name": "Ira, Fair Attendee",
      "cardSet": "DUSTER",
      "cardType": "CHARACTER",
      "versions": [
        { "reference": "ALT_DUSTER_B_AX_88_C",  "collectorNumberFormatedId": "SDU-002-C-EN", "faction": "AX", "rarity": "COMMON", "transfuge": false, "owned": 2, "imagePath": "https://.../en_US/...jpg" },
        { "reference": "ALT_DUSTER_B_AX_88_R1", "collectorNumberFormatedId": "SDU-002-R-EN", "faction": "AX", "rarity": "RARE",   "transfuge": false, "owned": 0, "imagePath": "..." },
        { "reference": "ALT_DUSTER_B_AX_88_R2", "collectorNumberFormatedId": "SDU-002-F-EN", "faction": "BR", "rarity": "RARE",   "transfuge": true,  "owned": 0, "imagePath": "..." }
      ]
    }
  ],
  "page": 1, "itemsPerPage": 30, "totalItems": 587, "totalPages": 20
}
```

> Fields use the same flattened format as `/api/collection` (single-locale strings; faction / rarity / cardType as codes).
> A version printed in several products (e.g. an alt-art) additionally carries `ownedCardProducts` — the list of products you own a copy of (e.g. `["A","B"]`).

### Adding a card (example)

```bash
POST /api/collection
Authorization: Bearer <token>
Content-Type: application/json

{
  "cardReference": "ALT_CORE_B_AX_01_C",
  "quantity": 3,
  "isFoil": false
}
```

Expected reference format: `ALT_CORE_B_AX_01_C` (regex `^ALT_[A-Z0-9]+_[A-Z0-9]+_[A-Z]+_\d+_[A-Z0-9]+(_\d+)?$`).
`quantity` must be an integer between 0 and 99.

### Available filters on `/api/collection`

```
# Exact match
?cardSet=COREKS
?faction=AX
?rarity=COMMON
?cardType=CHARACTER
?variation=standard

# Partial search
?cardReference=ALT_CORE
?name=Sierra
?subTypes=SOLDIER

# Value ranges (min / max)
?mainCost[gte]=2&mainCost[lte]=5
?recallCost[gte]=1
?oceanPower[gte]=3
?mountainPower[lte]=4
?forestPower[gte]=2

# Booleans
?isFoil=true
?isBanned=false
?isSuspended=false
```

---

## Rebuilding views

The read view (`collection_card_view`) can be rebuilt from the write model and altered-core metadata. Useful after a schema change, a catalogue update, or to repopulate metadata:

```bash
# Rebuild all views
symfony console collection:rebuild-views

# Choose the locale for labels / images
symfony console collection:rebuild-views --locale en

# Dry run without persisting
symfony console collection:rebuild-views --dry-run
```

---

## Tests

```bash
php bin/phpunit
```

---

## Contributing

The project is open to contributions from the Altered community.

### Before you start

**Join the `#dev` channel on the Altered Discord** and share what you want to work on.
Coordination is essential to avoid several people working on the same thing in parallel.

### Contribution rules

- All work is done via **Pull Request** — no direct push to `main`
- One PR = one feature or one fix
- Discuss the PR on Discord before submitting if the change is significant
- PRs must pass review from at least one other contributor before being merged

### Workflow

```
1. Fork / branch from main
2. Develop your feature
3. Open a Pull Request with a clear description
4. Discussion & review
5. Merge
```

### Contribution ideas

- New filters or sorts on the collection
- Statistics endpoints (completion by set/faction, etc.)
- Deck support
- Improved batch operations
- Automated tests
- Documentation

---

## License

Community project — card data belongs to Equinox.

---

---

# Altered Collection — API communautaire

API REST open source pour gérer la **collection personnelle de cartes** des joueurs du jeu **Altered**, construite avec Symfony 8, API Platform et PostgreSQL.
Elle permet à chaque utilisateur (authentifié via Keycloak) d'ajouter, modifier et supprimer les cartes de sa collection, avec quantités et variantes foil.

Les métadonnées des cartes (nom, faction, rareté, coûts, image, etc.) ne sont **pas** stockées en dur : elles sont récupérées à la volée depuis l'API [altered-core-cards-api](https://github.com/Altered-Community/altered-core-cards-api) puis mises en cache dans une vue de lecture.

---

## Stack technique

- **PHP 8.4** + **Symfony 8**
- **API Platform 4**
- **PostgreSQL 16**
- **Doctrine ORM 3** + migrations
- **Keycloak** (authentification JWT, validation via JWKS — `firebase/php-jwt`)
- **nelmio/cors-bundle** (CORS)
- **Twig** (page d'accueil)
- **Docker** (PostgreSQL via Symfony CLI)

---

## Architecture

L'API repose sur une séparation écriture / lecture (CQRS léger) :

- **`CollectionCard`** — modèle d'écriture. Source de vérité : `cardReference`, `quantity`, `isFoil`, lié à un `User`.
- **`CollectionCardView`** — modèle de lecture exposé par l'API. Reprend les champs d'écriture **et** les métadonnées de la carte (faction, rareté, coûts, image, sous-types…) récupérées depuis altered-core lors de l'écriture. C'est sur cette vue que portent les filtres.

À chaque création, les métadonnées sont récupérées via `AlteredCoreClient` (cache 1h par référence) et figées dans la vue. La commande `collection:rebuild-views` permet de reconstruire / rafraîchir toutes les vues.

---

## Installation

### Prérequis

- PHP 8.4
- Composer
- Symfony CLI
- Docker (pour PostgreSQL)
- Une instance **altered-core-cards-api** accessible (catalogue des cartes)
- Une instance **Keycloak** (sauf en mode dev auth, voir plus bas)

### 1. Cloner le projet

```bash
git clone <repo-url>
cd altered-core-collection-api
```

### 2. Installer les dépendances

```bash
composer install
```

### 3. Configurer l'environnement

```bash
cp .env .env.local
```

Éditer `.env.local` avec ta configuration :

```env
# Base de données
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/altered_collection?serverVersion=16&charset=utf8"

# Keycloak
KEYCLOAK_BASE_URL=http://localhost:8080
KEYCLOAK_REALM=altered
KEYCLOAK_CLIENT_ID=altered-collection
KEYCLOAK_CLIENT_SECRET=

# Authentification de dev (mint de tokens locaux sans Keycloak)
DEV_AUTH_ENABLED=false

# API altered-core (catalogue des cartes)
ALTERED_CORE_URL=http://localhost:41309
```

### 4. Démarrer la base de données

```bash
symfony server:start
```

Le Symfony CLI détecte automatiquement le `compose.yaml` et démarre PostgreSQL via Docker (base `altered_collection`, utilisateur `app`).

### 5. Créer la base et jouer les migrations

```bash
symfony console doctrine:database:create
symfony console doctrine:migrations:migrate
```

---

## Authentification

L'API est **stateless** et protégée par token JWT. Toutes les routes sous `/api` (hors `/api/dev/auth` et `/api/docs`) exigent un header :

```
Authorization: Bearer <token>
```

### Keycloak (production)

Le token est validé contre les clés publiques (JWKS) du realm Keycloak. À la première requête, un `User` local est créé automatiquement à partir du claim `sub` (et `email` / `preferred_username`).

### Mode dev

En mettant `DEV_AUTH_ENABLED=true`, une route permet de générer un token signé localement (HS256 via `APP_SECRET`), sans Keycloak :

```bash
POST /api/dev/auth
Content-Type: application/json

{ "sub": "dev-user-1", "email": "dev@example.com", "username": "dev-user" }
```

Réponse : `{ "token": "...", "expires_in": 3600, "payload": { ... } }`

---

## API

La documentation interactive (Swagger UI) est disponible à :

```
http://localhost/api/docs
```

### Endpoints de collection

| Méthode | Endpoint | Description |
|---|---|---|
| GET | `/api/collection` | Collection de l'utilisateur courant (filtrable) |
| GET | `/api/collection/{id}` | Une entrée de collection par ID |
| POST | `/api/collection` | Ajouter une carte à la collection |
| PATCH | `/api/collection/{id}` | Modifier une entrée (`quantity`, `isFoil`) |
| DELETE | `/api/collection/{id}` | Supprimer une entrée |

> Le PATCH utilise le content-type `application/merge-patch+json`.
> Chaque utilisateur ne voit et ne manipule que **sa propre** collection.

### Endpoints batch

Pour les opérations en masse (max **100** éléments par requête) :

| Méthode | Endpoint | Body | Réponse |
|---|---|---|---|
| POST | `/api/collection/batch` | `{"cards": [{"cardReference","quantity","isFoil"}, ...]}` | `{"created": [...], "skipped": [...]}` |
| PATCH | `/api/collection/batch` | `{"updates": [{"id","quantity","isFoil"}, ...]}` | `{"updated": N}` |
| DELETE | `/api/collection/batch` | `{"ids": [1, 2, 3]}` | `{"deleted": N}` |

> Le POST batch ignore silencieusement les cartes déjà présentes (`skipped`) et récupère toutes les métadonnées en un seul appel à altered-core.
> Le PATCH / DELETE batch ignorent silencieusement les IDs inexistants ou appartenant à un autre utilisateur.

### Endpoints playset

Vues en lecture seule pour suivre la **complétion du playset**. Seules les raretés COMMON / RARE / EXALTED et les types CHARACTER / SPELL / PERMANENT (dont LANDMARK_PERMANENT / EXPEDITION_PERMANENT) sont pris en compte ; les héros et la rareté UNIQUE sont exclus. CORE et COREKS partagent les mêmes cartes et sont fusionnés sous un seul set CORE, et les produits d'une carte (B booster, A/P alt-art / promo) sont repliés sur la référence B — les deux endpoints appliquent la même fusion.

| Méthode | Endpoint | Description |
|---|---|---|
| GET | `/api/collection/playset` | Statistiques de complétion par faction × set × bucket de quantité (0, 1, 2, 3+), avec agrégats par faction et par set. |
| GET | `/api/collection/playset/cards` | Tout l'univers playset, carte par carte — y compris les cartes possédées en 0 exemplaire (vue « liste de courses »). Paginé. |

#### `GET /api/collection/playset/cards`

Liste chaque carte du playset regroupée par référence de base, avec ses versions (C, R1, R2, E) et un `owned` par version. `owned` est **brut et non plafonné** (foil + non-foil et tous les cardProducts confondus) : les exemplaires COREKS et les réimpressions A/P (alt-art / promo) sont repliés sur la version **B** canonique. Les cartes possédées en 0 exemplaire sont incluses.

Paramètres de requête (tous optionnels, combinables) :

| Param | Description |
|---|---|
| `locale` | Locale du nom localisé (défaut `en`). |
| `cardSet[]` | Filtre par référence de set (niveau carte), ex. `cardSet[]=DUSTER`. |
| `faction[]` | Filtre par faction **réelle** de la version, ex. `faction[]=AX` (une R2 transfuge est filtrée sur sa faction d'accueil, ex. Bravos). |
| `cardType[]` | Filtre par type (niveau carte) : `CHARACTER`, `SPELL`, `PERMANENT`. |
| `rarity[]` | Ne garde que ces raretés de version : `COMMON`, `RARE` (garde R1 **et** R2), `EXALTED`. Omis = tout le périmètre. |
| `name` | Recherche partielle insensible à la casse sur le nom localisé. |
| `copies[]` | Garde les cartes dont au moins une version a un owned dans un bucket : `0`, `1-2`, `3`, `4plus`. |
| `page` | Numéro de page (défaut 1). |
| `itemsPerPage` | Nombre de cartes par page (défaut 30, max 100). |

```jsonc
{
  "summary": {
    "totalCards": 587,        // cartes correspondant aux filtres
    "totalVersions": 1542,    // versions cumulées sur ces cartes
    "totalOwned": 768,        // nombre total d'EXEMPLAIRES possédés (somme des owned, PAS un comptage de versions)
    "ownedBuckets": { "0": 1230, "1-2": 200, "3": 80, "4plus": 32 } // versions par palier d'owned ; somme = totalVersions (1542)
  }, // totaux sur tout le résultat filtré, toutes pages
  "items": [
    {
      "baseReference": "ALT_DUSTER_B_AX_88",
      "name": "Ira, Participant à la Foire",
      "cardSet": "DUSTER",
      "cardType": "CHARACTER",
      "versions": [
        { "reference": "ALT_DUSTER_B_AX_88_C",  "collectorNumberFormatedId": "SDU-002-C-EN", "faction": "AX", "rarity": "COMMON", "transfuge": false, "owned": 2, "imagePath": "https://.../fr_FR/...jpg" },
        { "reference": "ALT_DUSTER_B_AX_88_R1", "collectorNumberFormatedId": "SDU-002-R-EN", "faction": "AX", "rarity": "RARE",   "transfuge": false, "owned": 0, "imagePath": "..." },
        { "reference": "ALT_DUSTER_B_AX_88_R2", "collectorNumberFormatedId": "SDU-002-F-EN", "faction": "BR", "rarity": "RARE",   "transfuge": true,  "owned": 0, "imagePath": "..." }
      ]
    }
  ],
  "page": 1, "itemsPerPage": 30, "totalItems": 587, "totalPages": 20
}
```

> Les champs reprennent le format aplati de `/api/collection` (chaînes mono-locale ; faction / rareté / type en codes).
> Une version imprimée en plusieurs produits (ex. un alt-art) expose en plus `ownedCardProducts` — la liste des produits dont vous possédez un exemplaire (ex. `["A","B"]`).

### Ajout d'une carte (exemple)

```bash
POST /api/collection
Authorization: Bearer <token>
Content-Type: application/json

{
  "cardReference": "ALT_CORE_B_AX_01_C",
  "quantity": 3,
  "isFoil": false
}
```

Format de référence attendu : `ALT_CORE_B_AX_01_C` (regex `^ALT_[A-Z0-9]+_[A-Z0-9]+_[A-Z]+_\d+_[A-Z0-9]+(_\d+)?$`).
`quantity` doit être un entier entre 0 et 99.

### Filtres disponibles sur `/api/collection`

```
# Égalité exacte
?cardSet=COREKS
?faction=AX
?rarity=COMMON
?cardType=CHARACTER
?variation=standard

# Recherche partielle
?cardReference=ALT_CORE
?name=Sierra
?subTypes=SOLDIER

# Plages de valeurs (min / max)
?mainCost[gte]=2&mainCost[lte]=5
?recallCost[gte]=1
?oceanPower[gte]=3
?mountainPower[lte]=4
?forestPower[gte]=2

# Booléens
?isFoil=true
?isBanned=false
?isSuspended=false
```

---

## Reconstruction des vues

La vue de lecture (`collection_card_view`) peut être reconstruite à partir du modèle d'écriture et des métadonnées altered-core. Utile après un changement de schéma, une mise à jour du catalogue, ou pour repeupler les métadonnées :

```bash
# Reconstruire toutes les vues
symfony console collection:rebuild-views

# Choisir la locale des libellés / images
symfony console collection:rebuild-views --locale en

# Simulation sans persistance
symfony console collection:rebuild-views --dry-run
```

---

## Tests

```bash
php bin/phpunit
```

---

## Contribuer

Le projet est ouvert aux contributions de la communauté Altered.

### Avant de commencer

**Rejoins le canal `#dev` du Discord Altered** et présente ce sur quoi tu veux travailler.
La coordination est essentielle pour éviter que plusieurs personnes travaillent sur la même chose en parallèle.

### Règles de contribution

- Tout le travail se fait via **Pull Request** — aucun push direct sur `main`
- Une PR = une fonctionnalité ou un correctif
- Discuter de la PR sur Discord avant de la soumettre si le changement est conséquent
- Les PRs doivent passer la review d'au moins un autre contributeur avant d'être mergées

### Workflow

```
1. Fork / branche depuis main
2. Développe ta feature
3. Ouvre une Pull Request avec une description claire
4. Discussion & review
5. Merge
```

### Idées de contributions

- Nouveaux filtres ou tris sur la collection
- Endpoints de statistiques (complétion par set/faction, etc.)
- Support des decks
- Amélioration des opérations batch
- Tests automatisés
- Documentation

---

## Licence

Projet communautaire — les données des cartes appartiennent à Equinox.
