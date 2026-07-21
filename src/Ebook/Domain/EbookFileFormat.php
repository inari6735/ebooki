<?php declare(strict_types=1);

namespace App\Ebook\Domain;

/**
 * A downloadable eBook file format. Kept in sync with the accepted upload
 * formats (see EbookUploadRules::FILE_FORMATS).
 */
enum EbookFileFormat: string
{
    case PDF = 'pdf';
    case EPUB = 'epub';
    case MOBI = 'mobi';
    case AZW = 'azw';
    case AZW3 = 'azw3';
    case FB2 = 'fb2';
    case DJVU = 'djvu';
    case TXT = 'txt';
    case RTF = 'rtf';

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
