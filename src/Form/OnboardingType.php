<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Org;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Single-page wizard collecting Org slug+name, Tenant slug+name, and
 * the first token's name. All three are submitted in one POST and
 * persisted in a single transaction by the controller.
 */
final class OnboardingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('orgSlug', TextType::class, [
                'label' => 'Org slug',
                'help' => 'Lowercase, 3–32 chars, hyphens allowed (not at end). Used in URLs only — does not need to be globally unique.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(
                        pattern: Org::SLUG_REGEX,
                        message: 'Slug must match {{ pattern }} and not end with "-".',
                    ),
                ],
            ])
            ->add('orgName', TextType::class, [
                'label' => 'Org name',
                'help' => 'Display name. Anything goes, up to 128 chars.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 128),
                ],
            ])
            ->add('tenantSlug', TextType::class, [
                'label' => 'First tenant slug',
                'help' => 'Globally unique. This becomes the on-disk path under var/share/<signal>/<slug>/, so it cannot collide with another tenant on this installation.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(
                        pattern: Org::SLUG_REGEX,
                        message: 'Slug must match {{ pattern }} and not end with "-".',
                    ),
                ],
            ])
            ->add('tenantName', TextType::class, [
                'label' => 'First tenant name',
                'help' => 'Display name. Anything goes, up to 128 chars.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 128),
                ],
            ])
            ->add('tokenName', TextType::class, [
                'label' => 'First token name',
                'data' => 'default',
                'help' => 'A label so you can tell tokens apart later. The plaintext token will be shown exactly once on the next page.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 128),
                ],
            ])
            ->add('submit', SubmitType::class, ['label' => 'Create org and tenant'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'onboarding',
        ]);
    }
}
