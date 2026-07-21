<?php declare(strict_types=1);

namespace App\Ebook\Domain;

/**
 * Publication lifecycle of an eBook.
 */
enum EbookStatus: string
{
    case DRAFT = 'draft';
    case PENDING_REVIEW = 'pending_review';
    case PUBLISHED = 'published';
    case UNPUBLISHED = 'unpublished';
    case REJECTED = 'rejected';
}
