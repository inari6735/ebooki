<?php declare(strict_types=1);

namespace App\Ebook\Presentation\Form;

use App\Ebook\Domain\CategoryRepository;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\LanguageType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Single source of truth for the eBook form fields (types + options), so the publish
 * wizard steps ({@see DetailsStepType}, {@see PricingStepType}) and the edit form
 * ({@see EditEbookType}) share identical inputs. Validation lives on the shared
 * {@see \App\Ebook\Presentation\PublishEbook\PublishEbookData} DTO — never here — so
 * the rules for a given field can never diverge between "add" and "edit".
 */
final readonly class EbookFields
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    public function addDetailFields(FormBuilderInterface $builder): void
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

    public function addPricingFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('isFree', CheckboxType::class, ['required' => false])
            ->add('payWhatYouWant', CheckboxType::class, ['required' => false])
            ->add('price', NumberType::class, ['required' => false, 'html5' => true, 'scale' => 2])
            ->add('promoPrice', NumberType::class, ['required' => false, 'html5' => true, 'scale' => 2]);
    }
}
