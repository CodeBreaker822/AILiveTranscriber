@props([
    'title' => null,
    'activePage' => 'workspace',
    'focusedWorkspace' => true,
])

<x-shared::app-layout
    :title="$title"
    :active-page="$activePage"
    :focused-workspace="$focusedWorkspace"
    header-view="jerva.components.app-header"
    footer-view="jerva.components.app-footer"
    modal-namespace="jerva.modals"
>
    {{ $slot }}
</x-shared::app-layout>
