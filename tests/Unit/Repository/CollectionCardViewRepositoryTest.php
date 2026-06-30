<?php

namespace App\Tests\Unit\Repository;

use App\Entity\CollectionCardView;
use App\Entity\User;
use App\Repository\CollectionCardViewRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests the query *construction* of {@see CollectionCardViewRepository}. We build a real
 * EntityManager over the entity metadata but never connect to a database: the tests only inspect
 * the generated DQL. This keeps the suite runnable without a live PostgreSQL instance while still
 * guarding the case-insensitive `name` filter against regressions.
 */
class CollectionCardViewRepositoryTest extends TestCase
{
    private CollectionCardViewRepository $repository;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [dirname(__DIR__, 3) . '/src/Entity'],
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);

        // No server is ever contacted — the tests build the QueryBuilder and read its DQL only.
        // `serverVersion` is set so DBAL resolves the platform without ever connecting.
        $connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '16'], $config);
        $em         = new EntityManager($connection, $config);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(CollectionCardView::class)->willReturn($em);

        $this->repository = new CollectionCardViewRepository($registry);
    }

    /**
     * Before the fix the filter was `v.name LIKE :name`, which PostgreSQL evaluates
     * case-sensitively. Lower-casing both operands makes the match case- (but not accent-)
     * insensitive. The same DQL is produced whatever the term's case, which is exactly the point:
     * lowercase, uppercase, mixed case and a mid-name substring all hit the same comparison.
     */
    #[DataProvider('searchTerms')]
    public function testNameFilterIsCaseInsensitiveSubstringMatch(string $term): void
    {
        $qb = $this->repository->createFilteredQueryBuilder(new User(), ['name' => $term]);

        self::assertStringContainsString('LOWER(v.name) LIKE LOWER(:name)', $qb->getDQL());

        // Substring match on both sides; original case is preserved in the bound value because
        // the folding happens in SQL via LOWER(), not in PHP.
        self::assertSame('%' . $term . '%', $qb->getParameter('name')->getValue());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function searchTerms(): array
    {
        return [
            'lowercase'        => ['lucan'],
            'uppercase'        => ['LUCAN'],
            'mixed case'       => ['LuCaN'],
            'substring middle' => ['éviathan'],
        ];
    }

    public function testNameFilterIsSkippedWhenAbsent(): void
    {
        $qb = $this->repository->createFilteredQueryBuilder(new User(), []);

        self::assertStringNotContainsString('v.name', $qb->getDQL());
        self::assertNull($qb->getParameter('name'));
    }
}
