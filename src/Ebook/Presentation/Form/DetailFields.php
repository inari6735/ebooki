<?php declare(strict_types=1);

namespace App\Ebook\Presentation\Form;

use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Component\HttpFoundation\Request;

/**
 * The repeatable "Szczegółowe informacje" rows aren't part of the Symfony form (they
 * post as parallel detailKeys[]/detailValues[] arrays), so pairing + length checks
 * live here — shared verbatim by the wizard and the edit form so the rules match.
 */
final class DetailFields
{
    /** @return list<array{key: string, value: string}> */
    public static function collect(Request $request): array
    {
        $keys = $request->request->all('detailKeys');
        $values = $request->request->all('detailValues');
        $details = [];
        foreach ($keys as $i => $k) {
            $k = trim((string) $k);
            $v = trim((string) ($values[$i] ?? ''));
            if ('' !== $k && '' !== $v) {
                $details[] = ['key' => $k, 'value' => $v];
            }
        }

        return $details;
    }

    /**
     * @param list<array{key: string, value: string}> $details
     */
    public static function error(array $details): ?string
    {
        foreach ($details as $d) {
            if (mb_strlen($d['key']) > PublishEbookData::DETAIL_KEY_MAX
                || mb_strlen($d['value']) > PublishEbookData::DETAIL_VALUE_MAX) {
                return sprintf(
                    'Szczegółowe informacje: nazwa może mieć maksymalnie %d znaków, a wartość %d znaków.',
                    PublishEbookData::DETAIL_KEY_MAX,
                    PublishEbookData::DETAIL_VALUE_MAX,
                );
            }
        }

        return null;
    }
}
