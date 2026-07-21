<?php declare(strict_types=1);

namespace App\Ebook\Presentation\Form;

use App\Ebook\Presentation\PublishEbook\EbookUploadRules;
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
                        maxSize: EbookUploadRules::FILE_MAX_SIZE,
                        // Extension-only whitelist — never blocks a valid ebook whose
                        // content-type isn't in Symfony's MIME map.
                        extensions: EbookUploadRules::extensionOnly(EbookUploadRules::FILE_FORMATS),
                        extensionsMessage: 'Obsługiwane formaty: '.EbookUploadRules::label(EbookUploadRules::FILE_FORMATS).'.',
                        groups: ['file'],
                    ),
                ],
            ])
            ->add('cover', FileType::class, [
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Assert\File(
                        maxSize: EbookUploadRules::COVER_MAX_SIZE,
                        extensions: EbookUploadRules::COVER_FORMATS,
                        extensionsMessage: 'Obsługiwane formaty: '.EbookUploadRules::label(EbookUploadRules::COVER_FORMATS).'.',
                        groups: ['file'],
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
