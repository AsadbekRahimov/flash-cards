<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

final class ImportReport
{
    public int $added = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** `word_translations` rows created / changed (a changed row also counts its word as updated). */
    public int $translationsAdded = 0;

    public int $translationsUpdated = 0;

    /** @var list<string> */
    public array $errors = [];

    public bool $aborted = false;

    public function ok(): bool
    {
        return ! $this->aborted && $this->errors === [];
    }

    /** @return array{added:int,updated:int,skipped:int,translations_added:int,translations_updated:int,errors:list<string>,aborted:bool} */
    public function toArray(): array
    {
        return [
            'added' => $this->added,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'translations_added' => $this->translationsAdded,
            'translations_updated' => $this->translationsUpdated,
            'errors' => $this->errors,
            'aborted' => $this->aborted,
        ];
    }
}
