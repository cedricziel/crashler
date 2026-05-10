<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenant')]
#[ORM\UniqueConstraint(name: 'uniq_tenant_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
class Tenant
{
    public const SLUG_REGEX = Org::SLUG_REGEX;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Org::class, inversedBy: 'tenants')]
    #[ORM\JoinColumn(name: 'org_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?Org $org = null;

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
     * @var Collection<int, TenantToken>
     */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: TenantToken::class, cascade: ['remove'])]
    private Collection $tokens;

    /**
     * @var Collection<int, TenantMembership>
     */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: TenantMembership::class, cascade: ['remove'])]
    private Collection $memberships;

    public function __construct()
    {
        $this->tokens = new ArrayCollection();
        $this->memberships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrg(): ?Org
    {
        return $this->org;
    }

    public function setOrg(Org $org): self
    {
        $this->org = $org;

        return $this;
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
     * @return Collection<int, TenantToken>
     */
    public function getTokens(): Collection
    {
        return $this->tokens;
    }

    /**
     * @return Collection<int, TenantMembership>
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
