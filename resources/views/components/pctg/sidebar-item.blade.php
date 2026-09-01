@props([
    'active' => false
])

<div class="pctg-sidebar-item {{ $active ? 'pctg-sidebar-item-active' : '' }}">
    {{ $slot }}
</div>
