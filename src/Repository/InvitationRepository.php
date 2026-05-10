<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findOneByToken(string $token): ?Invitation
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Returns a pending (not-yet-accepted) invitation for the given
     * (tenant, email) pair if one exists. Used to enforce the
     * "one pending invitation per email per tenant" rule at service
     * level (works on Postgres + MariaDB; partial unique indexes are
     * Postgres-only).
     */
    public function findPendingByTenantAndEmail(Tenant $tenant, string $email): ?Invitation
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenant')
            ->andWhere('i.email = :email')
            ->andWhere('i.acceptedAt IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('email', mb_strtolower($email, 'UTF-8'))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Invitation>
     */
    public function findPendingForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenant')
            ->andWhere('i.acceptedAt IS NULL')
            ->setParameter('tenant', $tenant)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
