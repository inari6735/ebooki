<?php declare(strict_types=1);

namespace App\Ebook\Presentation\Form;

use App\Ebook\Domain\CategoryRepository;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\LanguageType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DetailsStepType extends AbstractType
{
    public function __construct(
        private readonly CategoryRepository $categories,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Categories come from the DB (see CategoryFixtures) — name => slug.
        $categoryChoices = [];
        foreach ($this->categories->all() as $category) {
            $categoryChoices[$category->getName()] = $category->getSlug();
        }

        $builder
            ->add('title', TextType::class, ['required' => false])
            ->add('author', TextType::class, ['required' => false])
            ->add('category', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Wybierz kategorię',
                'choices' => $categoryChoices,
            ])
            // Symfony's built-in world-language list, names shown in Polish;
            // PL/EN/DE surfaced at the top.
            ->add('language', LanguageType::class, [
                'required' => false,
                'placeholder' => false,
                'preferred_choices' => ['pl', 'en', 'de'],
                'choice_translation_locale' => 'pl',
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
