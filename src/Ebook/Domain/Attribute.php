<?php declare(strict_types=1);

namespace App\Ebook\Domain;

use InvalidArgumentException;

/**
 * A single "Szczegółowe informacje" row (label : value), e.g. "Liczba stron : 248".
 * Stored as part of the eBook's JSON `attributes` column — an ordered list of these.
 */
final readonly class Attribute
{
    public string $label;
    public string $value;

    public function __construct(string $label, string $value)
    {
        $label = trim($label);
        $value = trim($value);
        if ('' === $label || '' === $value) {
            throw new InvalidArgumentException('Attribute label and value must not be empty.');
        }
        $this->label = $label;
        $this->value = $value;
    }

    /** @return array{label: string, value: string} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'value' => $this->value];
    }

    /** @param array{label: string, value: string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['label'], $data['value']);
    }
}
