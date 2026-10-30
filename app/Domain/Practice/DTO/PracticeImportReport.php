<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Content\DTO\ImportIssue;

/**
 * What a practice import did (docs/16 §15.1), or why it refused: the twin of the report of the vocabulary import.
 *
 * The counters partition the tasks of the file that were written without an error: `added` (a new key), `updated` (a known
 * key whose text, target words, lesson or level changed - a changed text or word list becomes a NEW version), `skipped` (a known
 * key whose content is identical: nothing written). `published` counts the tasks this import made published; it is independent
 * of the three (an identical task that was still a draft is `skipped` and, with "publish at once", `published`).
 *
 * An import is all or nothing: with any issue `aborted` is true and the counters are zero. Every issue is an
 * {@see ImportIssue}: its `code` is stable, its `context` carries `position` (the number of the task in the file, from 1)
 * and `key` for the issues of one task, and the values the English message was built from, so that the admin panel words
 * the same refusal in Russian (AdminErrors::practiceImportIssue).
 */
final class PracticeImportReport
{
    public int $added = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public int $published = 0;

    /** @var list<string> English texts of the refusals, for the audit record and the console. */
    public array $errors = [];

    /** @var list<ImportIssue> The same refusals with a code and the values behind the text. */
    public array $issues = [];

    public bool $aborted = false;

    /**
     * Refuses the import for one more reason.
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

    /** @return array{added:int,updated:int,skipped:int,published:int,errors:list<string>,aborted:bool} */
    public function toArray(): array
    {
        return [
            'added' => $this->added,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'published' => $this->published,
            'errors' => $this->errors,
            'aborted' => $this->aborted,
        ];
    }
}
