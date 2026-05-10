<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Public-signup form. Used both at /signup and on the invitation-claim
 * page (where the same form fields apply but the surrounding gate is
 * the invitation token, not the global signup-enabled flag).
 *
 * Only collects email + password. Org/Tenant creation happens in the
 * onboarding wizard immediately after — keeping the signup surface
 * minimal makes it easier to debug + accessible.
 */
final class SignupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Email(),
                ],
                'data' => $options['prefilled_email'],
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'Password',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Confirm password',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'The passwords do not match.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 8, minMessage: 'Password must be at least {{ limit }} characters.'),
                ],
            ])
        ;

        if (null !== $options['terms_url']) {
            $builder->add('acceptTerms', CheckboxType::class, [
                'mapped' => false,
                'required' => true,
                'label' => 'I accept the terms of service',
                'constraints' => [
                    new Assert\IsTrue(message: 'You must accept the terms of service.'),
                ],
            ]);
        }

        $builder->add('submit', SubmitType::class, ['label' => 'Sign up']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'signup',
            'prefilled_email' => null,
            'terms_url' => null,
        ]);
        $resolver->setAllowedTypes('prefilled_email', ['null', 'string']);
        $resolver->setAllowedTypes('terms_url', ['null', 'string']);
    }
}
