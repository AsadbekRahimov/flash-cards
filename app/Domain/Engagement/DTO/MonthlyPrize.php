<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/** A prize of a place of the month (docs/13 §7.6): texts in ru / uz, picked by the interface locale with a fallback to ru. */
final readonly class MonthlyPrize
{
    public function __construct(
        public int $place,
        public string $ru,
        public string $uz,
    ) {}

    public function title(string $locale): string
    {
        $title = $locale === 'uz' ? $this->uz : $this->ru;
        if ($title === '') {
            $title = $this->ru !== '' ? $this->ru : $this->uz;
        }

        return $title;
    }

    /** @return array{place: int, ru: string, uz: string} */
    public function toArray(): array
    {
        return ['place' => $this->place, 'ru' => $this->ru, 'uz' => $this->uz];
    }
}
