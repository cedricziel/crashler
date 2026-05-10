<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrgRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrgRepository::class)]
#[ORM\Table(name: 'org')]
#[ORM\UniqueConstraint(name: 'uniq_org_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
class Org
{
    public const SLUG_REGEX = '/^[a-z][a-z0-9-]{2,31}(?<!-)$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: self::SLUG_REGEX, message: 'Slug must match {{ pattern }} and must not end with "-".')]
    private ?string $slug = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, Tenant>
     */
    #[ORM\OneToMany(mappedBy: 'org', targetEntity: Tenant::class)]
    private Collection $tenants;

    /**
     * @var Collection<int, OrgMembership>
     */
    #[ORM\OneToMany(mappedBy: 'org', targetEntity: OrgMembership::class)]
    private Collection $memberships;

    public function __construct()
    {
        $this->tenants = new ArrayCollection();
        $this->memberships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function getTenants(): Collection
    {
        return $this->tenants;
    }

    /**
     * @return Collection<int, OrgMembership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function __toString(): string
    {
        return $this->slug ?? '';
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if (null === $this->createdAt) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
