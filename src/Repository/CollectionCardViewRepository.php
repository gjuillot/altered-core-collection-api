<?php

namespace App\Repository;

use App\Entity\CollectionCardView;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CollectionCardViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CollectionCardView::class);
    }

    public function findOneByIdAndUser(int $id, User $user): ?CollectionCardView
    {
        return $this->createQueryBuilder('v')
            ->where('v.id = :id')
            ->andWhere('v.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return CollectionCardView[]
     */
    public function findByUserWithFilters(User $user, array $filters = []): array
    {
        return $this->createFilteredQueryBuilder($user, $filters)->getQuery()->getResult();
    }

    /**
     * Builds the filtered query for the connected user's collection. Extracted from
     * {@see findByUserWithFilters()} so the generated DQL can be asserted in tests
     * without a live database connection.
     */
    public function createFilteredQueryBuilder(User $user, array $filters = []): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('v')
            ->where('v.user = :user')
            ->setParameter('user', $user)
            ->orderBy('v.cardReference', 'ASC');

        if (!empty($filters['cardSet'])) {
            $qb->andWhere('v.cardSet IN (:cardSet)')->setParameter('cardSet', (array) $filters['cardSet']);
        }
        if (!empty($filters['faction'])) {
            $qb->andWhere('v.faction IN (:faction)')->setParameter('faction', (array) $filters['faction']);
        }
        if (!empty($filters['rarity'])) {
            $qb->andWhere('v.rarity IN (:rarity)')->setParameter('rarity', (array) $filters['rarity']);
        }
        if (!empty($filters['cardReference'])) {
            $qb->andWhere('v.cardReference LIKE :cardRef')->setParameter('cardRef', '%'.$filters['cardReference'].'%');
        }
        if (!empty($filters['name'])) {
            // Case-insensitive substring match: PostgreSQL LIKE is case-sensitive, so we
            // lower-case both the column and the pattern. (Accents are NOT normalised — see
            // findByUserWithFilters tests / would require the unaccent extension.)
            $qb->andWhere('LOWER(v.name) LIKE LOWER(:name)')->setParameter('name', '%'.$filters['name'].'%');
        }
        if (!empty($filters['cardType'])) {
            $qb->andWhere('v.cardType = :cardType')->setParameter('cardType', $filters['cardType']);
        }
        if (!empty($filters['variation'])) {
            $qb->andWhere('v.variation = :variation')->setParameter('variation', $filters['variation']);
        }
        if (!empty($filters['subTypes'])) {
            $this->applySubTypesFilter($qb, $filters['subTypes']);
        }
        if (array_key_exists('isFoil', $filters) && $filters['isFoil'] !== null && $filters['isFoil'] !== '') {
            $qb->andWhere('v.isFoil = :isFoil')
               ->setParameter('isFoil', filter_var($filters['isFoil'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('isBanned', $filters) && $filters['isBanned'] !== null && $filters['isBanned'] !== '') {
            $qb->andWhere('v.isBanned = :isBanned')
               ->setParameter('isBanned', filter_var($filters['isBanned'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('isSuspended', $filters) && $filters['isSuspended'] !== null && $filters['isSuspended'] !== '') {
            $qb->andWhere('v.isSuspended = :isSuspended')
               ->setParameter('isSuspended', filter_var($filters['isSuspended'], FILTER_VALIDATE_BOOLEAN));
        }
        $this->applyRangeFilter($qb, $filters, 'mainCost');
        $this->applyRangeFilter($qb, $filters, 'recallCost');
        $this->applyRangeFilter($qb, $filters, 'oceanPower');
        $this->applyRangeFilter($qb, $filters, 'mountainPower');
        $this->applyRangeFilter($qb, $filters, 'forestPower');

        return $qb;
    }

    /**
     * Supports both exact (?mainCost=3) and range (?mainCost[gte]=2&mainCost[lte]=5) syntax.
     * Operators: gte, lte, gt, lt, between (e.g. "2..5").
     */
    private function applyRangeFilter(\Doctrine\ORM\QueryBuilder $qb, array $filters, string $field): void
    {
        $value = $filters[$field] ?? null;
        if ($value === null || $value === '') {
            return;
        }

        if (!is_array($value)) {
            $qb->andWhere("v.$field = :$field")->setParameter($field, (int) $value);
            return;
        }

        $ops = ['gte' => '>=', 'lte' => '<=', 'gt' => '>', 'lt' => '<'];
        foreach ($ops as $key => $operator) {
            if (isset($value[$key]) && $value[$key] !== '') {
                $param = $field . '_' . $key;
                $qb->andWhere("v.$field $operator :$param")->setParameter($param, (int) $value[$key]);
            }
        }

        if (isset($value['between']) && str_contains((string) $value['between'], '..')) {
            [$min, $max] = explode('..', (string) $value['between'], 2);
            $qb->andWhere("v.$field BETWEEN :{$field}_min AND :{$field}_max")
               ->setParameter("{$field}_min", (int) $min)
               ->setParameter("{$field}_max", (int) $max);
        }
    }

    private function applySubTypesFilter(\Doctrine\ORM\QueryBuilder $qb, string $subType): void
    {
        $st = strtoupper(trim($subType));
        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->eq('v.subTypes', ':st_exact'),
                $qb->expr()->like('v.subTypes', ':st_start'),
                $qb->expr()->like('v.subTypes', ':st_middle'),
                $qb->expr()->like('v.subTypes', ':st_end'),
            )
        )
        ->setParameter('st_exact',  $st)
        ->setParameter('st_start',  $st . ',%')
        ->setParameter('st_middle', '%,' . $st . ',%')
        ->setParameter('st_end',    '%,' . $st);
    }

    /**
     * The user's owned cards (one row per owned reference) restricted to the given cardSets,
     * rarities and cardTypes. Returns the raw per-card quantity rather than pre-bucketed counts,
     * because the playset breakdown merges cards that exist in several editions (e.g. CORE and
     * COREKS share the same cards) by summing their quantities *before* bucketing — a step that
     * is impossible once the buckets are collapsed in SQL.
     *
     * Restricting to the same rarities and card types as the universe lookup keeps the
     * bucket-0 math (universe − owned) consistent.
     *
     * @param  string[] $sets
     * @param  string[] $rarities
     * @param  string[] $cardTypes
     * @return list<array{faction:string, cardSet:string, cardReference:string, quantity:int}>
     */
    public function findOwnedCardQuantities(User $user, array $sets, array $rarities, array $cardTypes): array
    {
        if (empty($sets) || empty($rarities) || empty($cardTypes)) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->select('v.faction AS faction', 'v.cardSet AS cardSet', 'v.cardReference AS cardReference', 'v.quantity AS quantity')
            ->where('v.user = :user')
            ->andWhere('v.cardSet IN (:sets)')
            ->andWhere('v.rarity IN (:rarities)')
            ->andWhere('v.cardType IN (:cardTypes)')
            ->setParameter('user', $user)
            ->setParameter('sets', $sets)
            ->setParameter('rarities', $rarities)
            ->setParameter('cardTypes', $cardTypes)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'faction'       => $row['faction'],
                'cardSet'       => $row['cardSet'],
                'cardReference' => $row['cardReference'],
                'quantity'      => (int) $row['quantity'],
            ],
            $rows,
        );
    }

    /** @return CollectionCardView[] */
    public function findByIdsAndUser(array $ids, User $user): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('v')
            ->where('v.id IN (:ids)')
            ->andWhere('v.user = :user')
            ->setParameter('ids', $ids)
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}
