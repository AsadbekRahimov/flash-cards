@php
    use App\Filament\Resources\LearnerResource\RelationManagers\ScoreDaysRelationManager;
    use App\Filament\Resources\Support\AdminDates;
    use App\Filament\Resources\Support\AdminLabels;

    $day = $this->scoreDay();
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.league-month-styles')

    @if ($day)
        <x-filament::section>
            <dl class="lm-head">
                <div>
                    <dt>{{ __('admin.learners.score_days.page.week') }}</dt>
                    <dd>
                        <x-filament::badge :color="ScoreDaysRelationManager::categoryColor($day->week_category)">{{ AdminLabels::value('learners.score_days.category', $day->week_category->value) }}</x-filament::badge>
                        {{ __('admin.learners.score_days.page.counts', ['points' => $day->week_points, 'new' => $day->week_new, 'review' => $day->week_review, 'long' => $day->week_long]) }}
                        @if ($day->week_capped_at)
                            <div class="lm-muted">{{ __('admin.learners.score_days.page.capped_at', ['when' => AdminDates::dateTime($day->week_capped_at)]) }}</div>
                        @endif
                        <div class="lm-muted">{{ __('admin.learners.score_days.page.version', ['version' => $day->week_version]) }}</div>
                    </dd>
                </div>
                <div>
                    <dt>{{ __('admin.learners.score_days.page.month') }}</dt>
                    <dd>
                        <x-filament::badge :color="ScoreDaysRelationManager::categoryColor($day->month_category)">{{ AdminLabels::value('learners.score_days.category', $day->month_category->value) }}</x-filament::badge>
                        {{ __('admin.learners.score_days.page.counts', ['points' => $day->month_points, 'new' => $day->month_new, 'review' => $day->month_review, 'long' => $day->month_long]) }}
                        @if ($day->month_capped_at)
                            <div class="lm-muted">{{ __('admin.learners.score_days.page.capped_at', ['when' => AdminDates::dateTime($day->month_capped_at)]) }}</div>
                        @endif
                        <div class="lm-muted">{{ __('admin.learners.score_days.page.version', ['version' => $day->month_version]) }}</div>
                    </dd>
                </div>
                <div>
                    <dt>{{ __('admin.learners.score_days.page.raw') }}</dt>
                    <dd>{{ __('admin.learners.score_days.page.counts', ['points' => $day->raw_points, 'new' => $day->new_count, 'review' => $day->review_count, 'long' => $day->long_count]) }}</dd>
                </div>
                <div>
                    <dt>{{ __('admin.learners.score_days.page.revision') }}</dt>
                    <dd>{{ $day->revision > 0 ? $day->revision : '—' }}</dd>
                </div>
            </dl>
            <p class="lm-note">{{ __('admin.learners.score_days.page.hint') }}</p>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
