<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Org;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Org>
 */
class OrgRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Org::class);
    }

    public function findOneBySlug(string $slug): ?Org
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
