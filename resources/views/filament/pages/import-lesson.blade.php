<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}

        <div class="mt-4 flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">
                Import
            </x-filament::button>
        </div>
    </form>

    @if ($lastReport)
        <div @class([
            'mt-6 rounded-lg border p-4',
            'border-success-600 bg-success-50 dark:bg-success-900/20' => empty($lastReport['errors']) && ! $lastReport['aborted'],
            'border-danger-600 bg-danger-50 dark:bg-danger-900/20' => $lastReport['aborted'] || ! empty($lastReport['errors']),
        ])>
            <h3 class="font-semibold mb-2">Last import report</h3>
            <ul class="text-sm space-y-1">
                <li>Added: <strong>{{ $lastReport['added'] }}</strong></li>
                <li>Updated: <strong>{{ $lastReport['updated'] }}</strong></li>
                <li>Skipped: <strong>{{ $lastReport['skipped'] }}</strong></li>
                <li>Aborted: <strong>{{ $lastReport['aborted'] ? 'yes' : 'no' }}</strong></li>
            </ul>

            @if (! empty($lastReport['errors']))
                <h4 class="font-semibold mt-3 mb-1">Errors</h4>
                <ul class="text-sm list-disc list-inside text-danger-700 dark:text-danger-300">
                    @foreach ($lastReport['errors'] as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</x-filament-panels::page>
