<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Engagement\DTO\RegionChoice;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Learners\Support\Regions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query of a table of the word points v2 (docs/04 A.19, A.19a; docs/32 §8.3; docs/35 §2.4):
 *
 *   category  free | pro - the table of that category (the other one read-only); default - the viewer's own;
 *   scope     all | region - the whole category, or a table of a region (default `all`);
 *   region    a code of `Regions` - the table of that region (any region may be opened, owner's decision docs/31 §5г); only with
 *             `scope=region`; without it `scope=region` is the viewer's own region (none → 422 `region_not_set`);
 *   after     the page after a cursor of exactly that table - only its shape is checked here, whose table it is the service decides
 *             (422 `invalid_cursor`).
 */
abstract class LeagueTableRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'nullable', 'string', Rule::in(array_map(static fn (ScoreCategory $c): string => $c->value, ScoreCategory::cases()))],
            'scope' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'region'])],
            'region' => ['sometimes', 'nullable', 'string', 'max:8', Rule::in(Regions::codes()), 'prohibited_unless:scope,region'],
            'after' => ['sometimes', 'nullable', 'string', 'max:250'],
        ];
    }

    public function requestedCategory(): ?ScoreCategory
    {
        $value = $this->query('category');

        return is_string($value) ? ScoreCategory::tryFrom($value) : null;
    }

    public function regionChoice(): RegionChoice
    {
        if ($this->query('scope') !== 'region') {
            return RegionChoice::all();
        }

        $region = $this->query('region');

        return is_string($region) && Regions::isValid($region) ? RegionChoice::of($region) : RegionChoice::own();
    }

    public function cursor(): ?string
    {
        $value = $this->query('after');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
