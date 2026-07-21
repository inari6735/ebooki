<?php declare(strict_types=1);

namespace App\Ebook\Presentation\PublishEbook;

/**
 * Single source of truth for accepted eBook / cover uploads. Used by both the
 * form validation (backend) and the templates (frontend labels + `accept`),
 * so the two can never drift apart.
 */
final class EbookUploadRules
{
    /**
     * Accepted eBook file extensions. A deliberate whitelist (no executables /
     * archives), but broad enough to cover the common reader/ebook formats so a
     * legitimate upload is never blocked. Extend this one list to allow more.
     *
     * @var list<string>
     */
    public const array FILE_FORMATS = ['pdf', 'epub', 'mobi', 'azw', 'azw3', 'fb2', 'djvu', 'txt', 'rtf'];
    public const string FILE_MAX_SIZE = '200M';
    public const int FILE_MAX_SIZE_MB = 200;

    /** @var list<string> accepted cover image extensions */
    public const array COVER_FORMATS = ['jpg', 'jpeg', 'png'];
    public const string COVER_MAX_SIZE = '10M';
    public const int COVER_MAX_SIZE_MB = 10;

    /**
     * Human label, e.g. "PDF, EPUB, MOBI" / "JPG, PNG" (jpeg folded into jpg).
     *
     * @param list<string> $formats
     */
    public static function label(array $formats): string
    {
        $labels = array_map('strtoupper', $formats);
        if (\in_array('JPG', $labels, true)) {
            $labels = array_values(array_filter($labels, static fn (string $l): bool => 'JPEG' !== $l));
        }

        return implode(', ', $labels);
    }

    /**
     * `accept` attribute value, e.g. ".pdf,.epub,.mobi".
     *
     * @param list<string> $formats
     */
    public static function accept(array $formats): string
    {
        return '.'.implode(',.', $formats);
    }

    /**
     * Extension whitelist for Assert\File WITHOUT the media-type coupling: a
     * valid file is never rejected just because its content-type isn't in
     * Symfony's MIME map (common for niche ebook formats like azw3/mobi). Deeper
     * content/virus validation is a later, separate stage.
     *
     * @param list<string> $formats
     * @return array<string, array<never>>
     */
    public static function extensionOnly(array $formats): array
    {
        return array_fill_keys($formats, []);
    }

    /** @return array<string, string|int> everything the upload step's template needs */
    public static function templateVars(): array
    {
        return [
            'fileFormats' => self::label(self::FILE_FORMATS),
            'fileAccept' => self::accept(self::FILE_FORMATS),
            'fileMaxMb' => self::FILE_MAX_SIZE_MB,
            'coverFormats' => self::label(self::COVER_FORMATS),
            'coverAccept' => self::accept(self::COVER_FORMATS),
            'coverMaxMb' => self::COVER_MAX_SIZE_MB,
        ];
    }
}
