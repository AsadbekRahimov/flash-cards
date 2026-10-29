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

    /** @var list<string> English texts of the refusals, for the audit record and logs. */
    public array $errors = [];

    /** @var list<ImportIssue> The same refusals with a code and the values behind the text, for a panel to word in its own language. */
    public array $issues = [];

    public bool $aborted = false;

    /**
     * Refuses the import for one more reason: `$message` (English) goes to {@see $errors}, the code and the values to {@see $issues}.
     *
     * @param  array<string, int|float|string|list<string>>  $context
     */
    public function abort(string $code, string $message, array $context = []): void
    {
        $this->aborted = true;
        $this->errors[] = $message;
        $this->issues[] = new ImportIssue($code, $message, $context);
    }

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
