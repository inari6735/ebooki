<?php declare(strict_types=1);

namespace App\Ebook\Presentation\Form;

use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Step 1 — eBook file + optional cover. Files are validated but NOT persisted
 * yet; the controller only records their client names for later preview.
 */
final class FileStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Assert\File(
                        maxSize: '200M',
                        extensions: ['pdf', 'epub', 'mobi'],
                        extensionsMessage: 'Obsługiwane formaty: PDF, EPUB, MOBI.',
                    ),
                ],
            ])
            ->add('cover', FileType::class, [
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Assert\File(
                        maxSize: '10M',
                        extensions: ['jpg', 'jpeg', 'png'],
                        extensionsMessage: 'Obsługiwane formaty: JPG, PNG.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PublishEbookData::class,
            'validation_groups' => ['file'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'ebook_file';
    }
}
