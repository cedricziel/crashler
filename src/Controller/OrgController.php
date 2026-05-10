<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\MembershipRole;
use App\Entity\Org;
use App\Entity\OrgMembership;
use App\Entity\User;
use App\Repository\OrgMembershipRepository;
use App\Repository\OrgRepository;
use App\Repository\UserRepository;
use App\Security\Voter\OrgVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class OrgController extends AbstractController
{
    #[Route(path: '/orgs', name: 'app_org_create', methods: ['POST'])]
    public function create(
        Request $request,
        OrgRepository $orgs,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->currentUser();

        $slug = trim((string) $request->request->get('slug', ''));
        $name = trim((string) $request->request->get('name', ''));
        if ('' === $slug || 1 !== preg_match(Org::SLUG_REGEX, $slug)) {
            throw $this->createNotFoundException('Invalid slug.');
        }
        if ('' === $name || mb_strlen($name) > 128) {
            throw $this->createNotFoundException('Invalid name.');
        }
        if (null !== $orgs->findOneBySlug($slug)) {
            $this->addFlash('error', \sprintf('Org slug "%s" is already taken.', $slug));

            return $this->redirectToRoute('app_dashboard');
        }

        $org = new Org();
        $org->setSlug($slug);
        $org->setName($name);
        $em->persist($org);

        $membership = new OrgMembership();
        $membership->setUser($user);
        $membership->setOrg($org);
        $membership->setRole(MembershipRole::Owner);
        $em->persist($membership);

        $em->flush();

        return $this->redirectToRoute('app_org_show', ['slug' => $org->getSlug()]);
    }

    #[Route(path: '/orgs/{slug}', name: 'app_org_show', methods: ['GET'])]
    public function show(
        string $slug,
        OrgRepository $orgs,
    ): Response {
        $org = $orgs->findOneBySlug($slug);
        if (null === $org) {
            throw new NotFoundHttpException();
        }
        $this->denyAccessUnlessGranted(OrgVoter::VIEW, $org);

        return $this->render('org/show.html.twig', [
            'org' => $org,
        ]);
    }

    #[Route(path: '/orgs/{slug}/memberships', name: 'app_org_membership_create', methods: ['POST'])]
    public function addMember(
        string $slug,
        Request $request,
        OrgRepository $orgs,
        UserRepository $users,
        OrgMembershipRepository $memberships,
        EntityManagerInterface $em,
    ): Response {
        $org = $orgs->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(OrgVoter::MANAGE, $org);

        $email = trim((string) $request->request->get('email', ''));
        $roleValue = (string) $request->request->get('role', 'member');
        $role = MembershipRole::tryFrom($roleValue) ?? MembershipRole::Member;

        $user = $users->findOneByEmail($email);
        if (null === $user) {
            $this->addFlash('error', \sprintf('No user with email "%s" exists yet. They need an account first.', $email));

            return $this->redirectToRoute('app_org_show', ['slug' => $slug]);
        }

        // Already a member?
        foreach ($memberships->findAllForUser($user) as $existing) {
            if ($existing->getOrg()?->getId() === $org->getId()) {
                $this->addFlash('error', \sprintf('%s is already a member of this org.', $email));

                return $this->redirectToRoute('app_org_show', ['slug' => $slug]);
            }
        }

        $m = new OrgMembership();
        $m->setUser($user);
        $m->setOrg($org);
        $m->setRole($role);
        $em->persist($m);
        $em->flush();

        return $this->redirectToRoute('app_org_show', ['slug' => $slug]);
    }

    #[Route(path: '/orgs/{slug}/memberships/{id}', name: 'app_org_membership_delete', methods: ['POST', 'DELETE'])]
    public function removeMember(
        string $slug,
        int $id,
        Request $request,
        OrgRepository $orgs,
        OrgMembershipRepository $memberships,
        EntityManagerInterface $em,
    ): Response {
        $org = $orgs->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(OrgVoter::MANAGE, $org);

        if (!$this->isCsrfTokenValid('delete-org-membership-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $m = $memberships->find($id);
        if (null === $m || $m->getOrg()?->getId() !== $org->getId()) {
            throw new NotFoundHttpException();
        }

        $em->remove($m);
        $em->flush();

        return $this->redirectToRoute('app_org_show', ['slug' => $slug]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
