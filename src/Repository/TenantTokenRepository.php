<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TenantToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantToken>
 */
class TenantTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantToken::class);
    }

    public function findOneByHash(string $hash): ?TenantToken
    {
        return $this->findOneBy(['hash' => $hash]);
    }

    /**
     * Loads every TenantToken with its parent Tenant pre-fetched in a single query.
     *
     * Used by DbTenantSource to assemble the registry without N+1.
     *
     * @return list<TenantToken>
     */
    public function findAllWithTenant(): array
    {
        return $this->createQueryBuilder('t')
            ->select('t', 'tn')
            ->innerJoin('t.tenant', 'tn')
            ->getQuery()
            ->getResult();
    }
}
