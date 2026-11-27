<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Learning\DTO\VocabularyCursor;
use App\Domain\Learning\Services\VocabularyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/twa/vocabulary (docs/04 A.25, docs/40 §2.3):
 *
 *   q       the search by the word and the translation, 1–50 characters after the trim (blank = no search);
 *   filter  all | weak | strong (default `all`);
 *   after   the cursor of the previous page - only its length is checked here, its shape the service checks (422 `invalid_cursor`);
 *   limit   1–50 rows (default 50).
 */
final class VocabularyRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:50'],
            'filter' => ['sometimes', 'nullable', 'string', Rule::in(VocabularyService::FILTERS)],
            'after' => ['sometimes', 'nullable', 'string', 'max:'.VocabularyCursor::MAX_LENGTH],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.VocabularyService::PAGE_SIZE],
        ];
    }

    public function search(): ?string
    {
        $value = $this->query('q');
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /** @return 'all'|'weak'|'strong' */
    public function filter(): string
    {
        return match ($this->query('filter')) {
            'weak' => 'weak',
            'strong' => 'strong',
            default => 'all',
        };
    }

    public function cursor(): ?string
    {
        $value = $this->query('after');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function limit(): int
    {
        $value = $this->query('limit');

        return is_numeric($value) ? (int) $value : VocabularyService::PAGE_SIZE;
    }
}
