@props(['activePage' => 'live'])

@php
    $workspace = $activePage === 'workspace';
    $emptyClasses = $workspace
        ? 'rounded-lg border border-dashed border-blue-200 bg-blue-50 p-4'
        : 'rounded-lg border border-dashed border-cyan-300/20 bg-cyan-300/5 p-4';
    $emptyTextClasses = $workspace ? 'text-sm text-blue-900' : 'text-sm text-slate-200';
@endphp

<div data-app-sidebar="pending" style="z-index: 2147483000;" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <button type="button" data-close-sidebar class="absolute inset-0 cursor-default {{ $workspace ? 'bg-blue-950/30' : 'bg-slate-950/75' }}" aria-label="Close pending audio panel"></button>

    <aside data-sidebar-panel class="absolute inset-y-0 right-0 flex w-[min(94vw,34rem)] translate-x-full flex-col border-l shadow-2xl transition-transform duration-300 ease-out {{ $workspace ? 'border-blue-200 bg-white text-black' : 'border-white/10 bg-slate-950 text-slate-100' }}" role="dialog" aria-modal="true" aria-label="Pending audio">
        <header class="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-3 {{ $workspace ? 'border-blue-200' : 'border-white/10' }}">
            <div>
                <p class="text-xs font-semibold uppercase {{ $workspace ? 'text-blue-600' : 'tracking-[0.28em] text-cyan-300' }}">Pending audio</p>
                <h2 class="mt-1 text-lg font-semibold {{ $workspace ? 'text-black' : 'text-white' }}">Pending clips</h2>
            </div>
            <button type="button" data-close-sidebar class="grid h-9 w-9 shrink-0 cursor-pointer place-items-center rounded-lg border transition {{ $workspace ? 'border-blue-200 bg-white text-blue-900 hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700' : 'border-white/10 bg-white/[0.03] text-slate-300 hover:bg-white/8 hover:text-white' }}" aria-label="Close pending audio panel">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m6 6 12 12" />
                    <path d="m18 6-12 12" />
                </svg>
            </button>
        </header>

        @if ($activePage === 'workspace')
            <div data-pending-mode-panel="live" data-audio-queue class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                <div data-audio-empty class="{{ $emptyClasses }}">
                    <p class="{{ $emptyTextClasses }}">No pending recordings yet.</p>
                </div>
            </div>

            <div data-pending-mode-panel="upload" data-upload-queue-list class="hidden min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                <div data-upload-empty class="{{ $emptyClasses }}">
                    <p class="{{ $emptyTextClasses }}">No pending recordings yet.</p>
                </div>
            </div>
        @elseif ($activePage === 'live')
            <div data-audio-queue class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                <div data-audio-empty class="{{ $emptyClasses }}">
                    <p class="{{ $emptyTextClasses }}">No pending recordings yet.</p>
                </div>
            </div>
        @else
            <div data-upload-queue-list class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                <div data-upload-empty class="{{ $emptyClasses }}">
                    <p class="{{ $emptyTextClasses }}">No pending recordings yet.</p>
                </div>
            </div>
        @endif
    </aside>
</div>
