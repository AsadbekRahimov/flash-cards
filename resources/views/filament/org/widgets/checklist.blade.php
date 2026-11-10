<x-filament-widgets::widget>
    @if ($checklist !== null)
        <x-filament::section :heading="__('org.dashboard.checklist.title')" :description="__('org.dashboard.checklist.progress', ['done' => $checklist->doneCount(), 'total' => count($checklist->steps())])" icon="heroicon-o-flag">
            <ul class="grid gap-3">
                @foreach ($checklist->steps() as $step => $done)
                    <li class="flex items-start gap-3" wire:key="checklist-{{ $step }}">
                        @if ($done)
                            <x-filament::icon icon="heroicon-o-check-circle" class="mt-0.5 h-6 w-6 shrink-0 text-success-600 dark:text-success-400" />
                        @else
                            <x-filament::icon icon="heroicon-o-minus-circle" class="mt-0.5 h-6 w-6 shrink-0 text-gray-400 dark:text-gray-500" />
                        @endif

                        <div class="text-sm">
                            <p class="{{ $done ? 'text-gray-500 line-through dark:text-gray-400' : 'font-medium text-gray-950 dark:text-white' }}">
                                {{ __('org.dashboard.checklist.'.$step) }}
                            </p>

                            @unless ($done)
                                <p class="text-gray-500 dark:text-gray-400">
                                    {{ __('org.dashboard.checklist.'.$step.'_help') }}
                                    @if (($links[$step] ?? null) !== null)
                                        <x-filament::link :href="$links[$step]" icon="heroicon-m-arrow-right" icon-position="after" size="sm">
                                            {{ __('org.dashboard.checklist.open') }}
                                        </x-filament::link>
                                    @endif
                                </p>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
