<x-filament-panels::page>
    @if ($this->rows() !== [])
        <x-filament::section>
            <dl class="grid gap-3 sm:grid-cols-2">
                @foreach ($this->rows() as $label => $value)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-base font-medium text-gray-950 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
    @endif

    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $this->message() }}</p>
    </x-filament::section>
</x-filament-panels::page>
