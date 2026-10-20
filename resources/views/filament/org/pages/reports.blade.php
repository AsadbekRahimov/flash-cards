<x-filament-panels::page>
    @php($range = $this->range())

    <x-filament::section>
        {{ $this->form }}

        @if ($range !== null)
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                {{ __('org.reports.period_label', ['from' => \Carbon\CarbonImmutable::parse($range->from)->format('d.m.Y'), 'to' => \Carbon\CarbonImmutable::parse($range->to)->format('d.m.Y')]) }}
            </p>
        @else
            <p class="mt-3 text-sm text-danger-600 dark:text-danger-400">{{ $this->rangeError() }}</p>
        @endif
    </x-filament::section>

    @if ($range !== null)
        @php($groups = $this->groupRows())
        @php($learners = $this->learnerRows())

        <x-filament::section :heading="__('org.reports.groups.heading')">
            @if ($groups === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('org.reports.groups.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-sm divide-y divide-gray-200 dark:divide-white/10">
                        <thead>
                            <tr class="text-start font-semibold text-gray-950 dark:text-white">
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.group') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.learners') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.active_learners') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.reviews') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.new_words') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.goal_met_days') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.assignments') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($groups as $row)
                                <tr wire:key="group-{{ $row->groupId }}">
                                    <td class="px-3 py-2">{{ $row->title }}</td>
                                    <td class="px-3 py-2">{{ $row->learners }}</td>
                                    <td class="px-3 py-2">{{ $row->activeLearners }}</td>
                                    <td class="px-3 py-2">{{ $row->reviews }}</td>
                                    <td class="px-3 py-2">{{ $row->newWords }}</td>
                                    <td class="px-3 py-2">{{ $row->goalMetDays }}</td>
                                    <td class="px-3 py-2">{{ $row->assignmentsDone }} / {{ $row->assignmentsTotal }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('org.reports.learners.heading')">
            @if ($learners === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('org.reports.learners.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-sm divide-y divide-gray-200 dark:divide-white/10">
                        <thead>
                            <tr class="text-start font-semibold text-gray-950 dark:text-white">
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.learner') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.reviews') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.new_words') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.days_active') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.goal_met_days') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.streak') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.assignments') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('org.reports.columns.last_seen') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($learners as $row)
                                <tr wire:key="learner-{{ $row->learnerId }}">
                                    <td class="px-3 py-2">{{ $row->name }}</td>
                                    <td class="px-3 py-2">{{ $row->reviews }}</td>
                                    <td class="px-3 py-2">{{ $row->newWords }}</td>
                                    <td class="px-3 py-2">{{ $row->daysActive }}</td>
                                    <td class="px-3 py-2">{{ $row->goalMetDays }}</td>
                                    <td class="px-3 py-2">{{ $row->streakCurrent }}</td>
                                    <td class="px-3 py-2">{{ $row->assignmentsDone }} / {{ $row->assignmentsTotal }}</td>
                                    <td class="px-3 py-2">{{ $this->formatSeen($row->lastSeenAt) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('org.reports.privacy') }}</p>
    @endif
</x-filament-panels::page>
