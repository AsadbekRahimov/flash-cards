{{-- Polls only while the Telegram account is not verified: once the bot has confirmed it, the wrapper has no wire:poll and polling stops. --}}
<div @unless ($verified) wire:poll.10s @endunless class="text-sm text-gray-950 dark:text-white">
    {{ $text }}
</div>
