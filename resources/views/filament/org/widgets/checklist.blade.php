{{-- The panel has no Tailwind build of its own: the layout is the `lx-checklist` rules of resources/css/filament/panel.css. --}}
<x-filament-widgets::widget>
    @if ($checklist !== null)
        <x-filament::section :heading="__('org.dashboard.checklist.title')" :description="__('org.dashboard.checklist.progress', ['done' => $checklist->doneCount(), 'total' => count($checklist->steps())])" icon="heroicon-o-flag">
            <ul class="lx-checklist">
                @foreach ($checklist->steps() as $step => $done)
                    <li class="lx-checklist-step" wire:key="checklist-{{ $step }}">
                        @if ($done)
                            <x-filament::icon icon="heroicon-o-check-circle" class="lx-checklist-icon lx-checklist-done" />
                        @else
                            <x-filament::icon icon="heroicon-o-minus-circle" class="lx-checklist-icon" />
                        @endif

                        <div class="lx-checklist-text">
                            <p @class(['lx-checklist-title', 'lx-checklist-title-done' => $done])>
                                {{ __('org.dashboard.checklist.'.$step) }}
                            </p>

                            @unless ($done)
                                <p class="lx-muted">
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
