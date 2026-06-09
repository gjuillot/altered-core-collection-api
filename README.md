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
