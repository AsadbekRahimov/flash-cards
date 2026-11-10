{{-- Polls only while a live, unspent link waits for the bot's confirmation: once verified, expired or spent, the wrapper has no wire:poll and polling stops. --}}
<div @if ($poll) wire:poll.10s @endif class="text-sm text-gray-950 dark:text-white">
    {{ $text }}
</div>
