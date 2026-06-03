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
            $qb->andWhere('v.name LIKE :name')->setParameter('name', '%'.$filters['name'].'%');
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

        return $qb->getQuery()->getResult();
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
     * Count the user's distinct owned cardReferences per faction × cardSet, split into
     * quantity buckets: exactly 1, exactly 2, and 3 or more. Only the given cardSets, rarities
     * and cardTypes are considered. (One row per (user, cardReference) is guaranteed by the
     * unique constraint, so counting rows counts distinct references.)
     *
     * Restricting to the same rarities and card types as the universe lookup keeps the
     * bucket-0 math (universe − owned) consistent.
     *
     * @param  string[] $sets
     * @param  string[] $rarities
     * @param  string[] $cardTypes
     * @return array<string, array{1:int, 2:int, '3+':int}>  keyed by "FACTION|CARDSET"
     */
    public function countOwnedBucketsByFactionAndSet(User $user, array $sets, array $rarities, array $cardTypes): array
    {
        if (empty($sets) || empty($rarities) || empty($cardTypes)) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->select(
                'v.faction AS faction',
                'v.cardSet AS cardSet',
                'SUM(CASE WHEN v.quantity = 1 THEN 1 ELSE 0 END) AS b1',
                'SUM(CASE WHEN v.quantity = 2 THEN 1 ELSE 0 END) AS b2',
                'SUM(CASE WHEN v.quantity >= 3 THEN 1 ELSE 0 END) AS b3plus',
            )
            ->where('v.user = :user')
            ->andWhere('v.cardSet IN (:sets)')
            ->andWhere('v.rarity IN (:rarities)')
            ->andWhere('v.cardType IN (:cardTypes)')
            ->setParameter('user', $user)
            ->setParameter('sets', $sets)
            ->setParameter('rarities', $rarities)
            ->setParameter('cardTypes', $cardTypes)
            ->groupBy('v.faction')
            ->addGroupBy('v.cardSet')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['faction'] . '|' . $row['cardSet']] = [
                '1'  => (int) $row['b1'],
                '2'  => (int) $row['b2'],
                '3+' => (int) $row['b3plus'],
            ];
        }

        return $result;
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
