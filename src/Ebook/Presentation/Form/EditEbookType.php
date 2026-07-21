<?php declare(strict_types=1);

namespace App\Ebook\Presentation\Form;

use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The edit form: the same detail + pricing fields the wizard uses (via
 * {@see EbookFields}), on one page, validated with both DTO groups — so an eBook is
 * edited with exactly the same inputs, options and validation as when it was added.
 */
final class EditEbookType extends AbstractType
{
    public function __construct(private readonly EbookFields $fields)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->fields->addDetailFields($builder);
        $this->fields->addPricingFields($builder);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PublishEbookData::class,
            'validation_groups' => ['details', 'pricing'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'ebook_edit';
    }
}
