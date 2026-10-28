{{-- Inline icons of the landing: 24x24 outline, colour from `currentColor`, purely decorative (aria-hidden).
     $name: chat | clock | speaker | globe. No external files, no <use href>. --}}
<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('chat')
            <path d="M4 5.5h16a1 1 0 0 1 1 1V16a1 1 0 0 1-1 1h-9l-4.5 3.5V17H4a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1Z"/>
            <path d="M8 11.25h.01M12 11.25h.01M16 11.25h.01"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9"/>
            <path d="M12 7.5V12l3 2"/>
            @break
        @case('speaker')
            <path d="M11 5 6.5 9H3v6h3.5L11 19V5Z"/>
            <path d="M15.5 9.2a4 4 0 0 1 0 5.6M18.4 6.4a8 8 0 0 1 0 11.2"/>
            @break
        @case('globe')
            <circle cx="12" cy="12" r="9"/>
            <path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3Z"/>
            @break
    @endswitch
</svg>
