{{-- The panels run in Filament SPA mode (docs/20 §1.14): a middleware redirect to this page during a wire:navigate click
     is followed by fetch, and Livewire swaps this body into the panel document (panel stylesheet stays, ours is added).
     Livewire exists only in such a swapped document — a plain visit loads none — so there is nothing to do on a full load. --}}
<script>
    if (window.Livewire) {
        window.location.reload();
    }
</script>
