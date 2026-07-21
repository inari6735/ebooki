<?php declare(strict_types=1);

namespace App\Ebook\Presentation\Form;

use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DetailsStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['required' => false])
            ->add('author', TextType::class, ['required' => false])
            ->add('category', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Wybierz kategorię',
                'choices' => [
                    'Rozwój osobisty' => 'rozwoj-osobisty',
                    'Biznes' => 'biznes',
                    'Marketing' => 'marketing',
                    'Fantastyka' => 'fantastyka',
                    'Literatura' => 'literatura',
                ],
            ])
            ->add('language', ChoiceType::class, [
                'required' => false,
                'choices' => ['Polski' => 'pl', 'Angielski' => 'en', 'Niemiecki' => 'de'],
            ])
            ->add('shortDescription', TextType::class, ['required' => false])
            ->add('description', TextareaType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PublishEbookData::class,
            'validation_groups' => ['details'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'ebook_details';
    }
}
