<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php($report = $this->report())

    <x-filament::section :heading="__('org.hard_words.heading')" :description="__('org.hard_words.description')">
        @if ($report['rows'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('org.hard_words.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-start text-sm divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                        <tr class="text-start font-semibold text-gray-950 dark:text-white">
                            <th class="px-3 py-2 text-start">{{ __('org.hard_words.columns.word') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('org.hard_words.columns.translation') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('org.hard_words.columns.learners') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('org.hard_words.columns.confused_with') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($report['rows'] as $row)
                            <tr wire:key="hard-word-{{ $row['word_id'] }}">
                                <td class="px-3 py-2">{{ $row['word'] }}</td>
                                <td class="px-3 py-2">{{ $row['translation'] }}</td>
                                <td class="px-3 py-2">{{ __('org.hard_words.learners_of', ['count' => $row['learners'], 'total' => $report['class_size']]) }}</td>
                                <td class="px-3 py-2">{{ $row['confused_with'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
