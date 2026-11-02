<?php

declare(strict_types=1);

namespace App\Filament\Resources\Support;

use App\Domain\Administration\Services\PanelDataScope;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "group" filter of the lists of learners and exams. The groups of the platform are over a thousand: a select that renders
 * them all put every title into the HTML of the page (about 240 bytes of markup each, 340 KB for 1 400 groups, docs/20 §4)
 * and read them all from the database on every render. This one offers the first {@see LIMIT} groups by title and finds the
 * others by what is typed (a search of the select, one small query), the way Filament's own relationship filter does. A
 * teacher is offered the groups of their own only ({@see PanelDataScope::groups()}).
 */
final class GroupFilter
{
    /** How many groups the select offers at once. */
    public const LIMIT = 50;

    public static function make(string $name, string $label): SelectFilter
    {
        return SelectFilter::make($name)
            ->label($label)
            ->searchable()
            ->options(fn (): array => self::search(''))
            ->getSearchResultsUsing(fn (string $search): array => self::search($search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => self::title($value))
            // The default indicator looks the group up in `options()`, which holds only the first groups.
            ->indicateUsing(function (array $state) use ($label): array {
                $title = self::title($state['value'] ?? null);

                return $title === null ? [] : [Indicator::make($label.': '.$title)];
            });
    }

    /**
     * @return array<int, string> group id => title, the viewer's groups whose title contains $search
     */
    public static function search(string $search): array
    {
        $term = trim($search);

        return app(PanelDataScope::class)->groups()
            ->when($term !== '', fn (Builder $groups): Builder => $groups->whereLike('title', '%'.$term.'%'))
            ->orderBy('title')
            ->limit(self::LIMIT)
            ->pluck('title', 'id')
            ->all();
    }

    /** The title of one of the viewer's groups; null for no group or a group the viewer may not see. */
    public static function title(mixed $id): ?string
    {
        if (! is_numeric($id)) {
            return null;
        }

        $title = app(PanelDataScope::class)->groups()->whereKey((int) $id)->value('title');

        return is_string($title) ? $title : null;
    }
}
