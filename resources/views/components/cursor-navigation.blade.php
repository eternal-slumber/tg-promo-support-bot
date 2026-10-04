@props(['paginator', 'name', 'label', 'previousLabel', 'nextLabel', 'latestLabel' => null])

@if ($paginator->hasPages())
    <nav aria-label="{{ $label }}" {{ $attributes->class('flex flex-wrap items-center gap-2 text-xs') }}>
        @if ($cursor = $paginator->previousCursor())
            <button wire:click="setPage('{{ $cursor->encode() }}', '{{ $name }}')" wire:key="{{ $name }}-previous-{{ $cursor->encode() }}" type="button" class="rounded-lg border border-slate-200 px-2.5 py-2 hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" wire:loading.attr="disabled">{{ $previousLabel }}</button>
        @endif
        @if ($cursor = $paginator->nextCursor())
            <button wire:click="setPage('{{ $cursor->encode() }}', '{{ $name }}')" wire:key="{{ $name }}-next-{{ $cursor->encode() }}" type="button" class="rounded-lg border border-slate-200 px-2.5 py-2 hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" wire:loading.attr="disabled">{{ $nextLabel }}</button>
        @endif
        @if ($latestLabel !== null && ! $paginator->onFirstPage())
            <button wire:click="resetPage('{{ $name }}')" type="button" class="rounded-lg px-2.5 py-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" wire:loading.attr="disabled">{{ $latestLabel }}</button>
        @endif
    </nav>
@endif
