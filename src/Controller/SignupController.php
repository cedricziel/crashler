<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\SignupType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public signup. Gated by `crashler.signup.enabled`; returns 404 (not
 * 403) when disabled so the surface is invisible to anonymous probing.
 *
 * On valid POST, persists a User with ROLE_USER and zero memberships,
 * logs them in programmatically, and redirects to /dashboard/onboarding
 * where they create their first Org + Tenant + Token.
 */
final class SignupController extends AbstractController
{
    public function __construct(
        #[Autowire(param: 'crashler.signup.enabled')]
        private readonly bool $signupEnabled,
        #[Autowire(param: 'crashler.signup.terms_url')]
        private readonly ?string $termsUrl,
    ) {
    }

    #[Route(path: '/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        Security $security,
    ): Response {
        if (!$this->signupEnabled) {
            throw new NotFoundHttpException();
        }

        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(SignupType::class, null, [
            'terms_url' => $this->termsUrl,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $form->get('email')->getData();
            $plaintext = (string) $form->get('plainPassword')->getData();

            if (null !== $users->findOneByEmail($email)) {
                $form->get('email')->addError(new \Symfony\Component\Form\FormError('This email is already registered.'));
            } else {
                $user = new User();
                $user->setEmail($email);
                $user->setRoles([]); // Defaults to ROLE_USER via getRoles()
                $user->setPassword($passwordHasher->hashPassword($user, $plaintext));
                $em->persist($user);
                $em->flush();

                $security->login($user, 'form_login', 'main');

                return $this->redirectToRoute('app_dashboard_onboarding');
            }
        }

        return $this->render('signup/index.html.twig', [
            'form' => $form,
            'terms_url' => $this->termsUrl,
        ]);
    }
}
