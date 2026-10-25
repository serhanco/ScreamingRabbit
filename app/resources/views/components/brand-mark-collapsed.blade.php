{{-- Shown at the top of the collapsed sidebar: just the logo mark, which also expands the sidebar again. --}}
<button
    type="button"
    x-data="{}"
    x-cloak
    x-show="! $store.sidebar.isOpen"
    x-on:click="$store.sidebar.open()"
    aria-controls="fi-main-sidebar"
    title="Menüyü aç"
    class="sr-collapsed-mark"
    style="display:flex;align-items:center;justify-content:center;width:2.5rem;height:2.5rem;margin:0 auto;border-radius:.5rem"
>
    <img src="{{ asset('images/logo-mark.svg') }}" alt="ScreamingRabbit" style="height:2rem;width:2rem">
</button>
<style>
    /* The mark replaces Filament's separate expand chevron in the collapsed sidebar. */
    @media (min-width: 1024px) { .fi-sidebar-open-collapse-sidebar-btn { display: none !important; } }
</style>
