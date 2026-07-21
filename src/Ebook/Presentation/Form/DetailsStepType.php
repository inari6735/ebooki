<?php declare(strict_types=1);

namespace App\Ebook\Presentation\Form;

use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DetailsStepType extends AbstractType
{
    public function __construct(private readonly EbookFields $fields)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->fields->addDetailFields($builder);
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
