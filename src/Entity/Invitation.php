<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\MembershipRole;
use App\Repository\InvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitation')]
#[ORM\UniqueConstraint(name: 'uniq_invitation_token', columns: ['token'])]
// Partial unique index (Postgres-only — DBAL emits an `WHERE accepted_at IS NULL`
// fragment from `options.where`). MariaDB ignores the `where` option silently
// because it doesn't support partial indexes; the same invariant is enforced
// at the service level by InvitationRepository::findPendingByTenantAndEmail().
#[ORM\UniqueConstraint(
    name: 'uniq_invitation_pending_tenant_email',
    columns: ['tenant_id', 'email'],
    options: ['where' => '(accepted_at IS NULL)'],
)]
#[ORM\HasLifecycleCallbacks]
class Invitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 16, enumType: MembershipRole::class)]
    private MembershipRole $role = MembershipRole::Member;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $token = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'accepted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'accepted_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        // Normalise on write so claim-flow comparison is case-insensitive
        // (matches User.email_lower from Change 1).
        $this->email = mb_strtolower($email, 'UTF-8');

        return $this;
    }

    public function getRole(): MembershipRole
    {
        return $this->role;
    }

    public function setRole(MembershipRole $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getAcceptedBy(): ?User
    {
        return $this->acceptedBy;
    }

    public function markAccepted(User $user, \DateTimeImmutable $at): void
    {
        $this->acceptedAt = $at;
        $this->acceptedBy = $user;
    }

    public function isAccepted(): bool
    {
        return null !== $this->acceptedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt < $now;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if (null === $this->createdAt) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
