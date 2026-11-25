<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Engagement\Enums\ScoreCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/twa/league/month?category=free|pro&after=… (docs/04 A.19, docs/32 §8.3). `category` opens a table of a v2 month (the other
 * one read-only); `after` asks for the page after a cursor of that table - only its shape is checked here, whose table it is, the
 * service decides (422 `invalid_cursor`). Both are ignored by a v1 month, except that a v1 month has no pages.
 */
final class MonthlyRatingRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'nullable', 'string', Rule::in(array_map(static fn (ScoreCategory $c): string => $c->value, ScoreCategory::cases()))],
            'after' => ['sometimes', 'nullable', 'string', 'max:250'],
        ];
    }

    public function requestedCategory(): ?ScoreCategory
    {
        $value = $this->query('category');

        return is_string($value) ? ScoreCategory::tryFrom($value) : null;
    }

    public function cursor(): ?string
    {
        $value = $this->query('after');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
