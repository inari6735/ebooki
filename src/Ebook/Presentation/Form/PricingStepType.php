<?php declare(strict_types=1);

namespace App\Ebook\Presentation\Form;

use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PricingStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('price', NumberType::class, [
                'required' => false,
                'html5' => true,
                'scale' => 2,
            ])
            ->add('promoPrice', NumberType::class, [
                'required' => false,
                'html5' => true,
                'scale' => 2,
            ])
            ->add('freeFragment', CheckboxType::class, ['required' => false])
            ->add('salesModel', ChoiceType::class, [
                'required' => false,
                'expanded' => true,
                'placeholder' => false,
                'choices' => [
                    'public' => 'public',
                    'private' => 'private',
                    'limited' => 'limited',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PublishEbookData::class,
            'validation_groups' => ['pricing'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'ebook_pricing';
    }
}
