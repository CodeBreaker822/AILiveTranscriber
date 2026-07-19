@props([
    'title' => null,
    'activePage' => 'live',
    'focusedWorkspace' => false,
])

<x-shared::app-layout
    :title="$title"
    :active-page="$activePage"
    :focused-workspace="$focusedWorkspace"
    header-view="astra.components.app-header"
    footer-view="astra.components.app-footer"
    modal-namespace="astra.modals"
>
    {{ $slot }}
</x-shared::app-layout>
