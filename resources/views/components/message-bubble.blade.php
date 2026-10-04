@props(['message', 'ticketId' => null])

@php
    $isParticipant = $message->author === \App\Enums\MessageAuthor::Participant;
    $isBot = $message->author === \App\Enums\MessageAuthor::Bot;
    $isEvent = $message->author === \App\Enums\MessageAuthor::System || $message->ticket_event !== null;
@endphp

<article {{ $attributes->class(['flex min-w-0', 'justify-center' => $isEvent, 'justify-end' => ! $isEvent && $isParticipant, 'justify-start' => ! $isEvent && ! $isParticipant]) }}>
    <div @class([
        'min-w-0 max-w-[88%] sm:max-w-[78%]',
        'text-center text-xs text-slate-500 dark:text-slate-400' => $isEvent,
        'rounded-2xl px-4 py-3 shadow-xs' => ! $isEvent,
        'rounded-br-md bg-emerald-600 text-white dark:bg-emerald-700' => ! $isEvent && $isParticipant,
        'rounded-bl-md border border-emerald-100 bg-emerald-50 text-slate-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-slate-100' => ! $isEvent && $isBot,
        'rounded-bl-md border border-slate-200 bg-white text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100' => ! $isEvent && ! $isParticipant && ! $isBot,
    ])>
        <div @class(['mb-1 text-xs font-medium', 'text-emerald-100' => $isParticipant && ! $isEvent, 'text-slate-500 dark:text-slate-400' => ! $isParticipant || $isEvent])>
            <span @class(['inline-flex items-center gap-1.5', 'rounded bg-emerald-100 px-1.5 py-0.5 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' => $isBot && ! $isEvent])>
                @if ($isBot)
                    <svg aria-hidden="true" viewBox="0 0 16 16" class="size-3 fill-current"><path d="M7 0h2v3h4a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4V0ZM4 6v3h2V6H4Zm6 0v3h2V6h-2ZM5 11v1h6v-1H5Z"/></svg>
                @endif
                {{ $message->author->label() }}@if ($ticketId !== null)<span class="sr-only"> · Обращение #{{ $ticketId }}</span>@endif
            </span>
        </div>
        <p @class(['whitespace-pre-wrap wrap-anywhere leading-relaxed', 'text-sm' => ! $isEvent])>{{ $message->body }}</p>
        <div @class(['mt-1.5 flex flex-wrap gap-x-2 gap-y-1 text-[10px]', 'justify-center' => $isEvent, 'justify-end text-emerald-100' => $isParticipant && ! $isEvent, 'text-slate-500 dark:text-slate-400' => ! $isParticipant || $isEvent])>
            <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</time>
            @if ($message->direction === \App\Enums\MessageDirection::Outbound && $message->delivery_status !== null)
                <span @class(['text-rose-600 dark:text-rose-400' => $message->delivery_status === \App\Enums\DeliveryStatus::Failed])>Доставка: {{ $message->delivery_status->label() }}</span>
            @endif
        </div>
        @if ($slot->isNotEmpty())
            <div class="mt-2 flex flex-wrap items-center gap-2">{{ $slot }}</div>
        @endif
    </div>
</article>
