@props(['message', 'ticketId' => null, 'previousMessage' => null])

@php
    $isParticipant = $message->author === \App\Enums\MessageAuthor::Participant;
    $isBot = $message->author === \App\Enums\MessageAuthor::Bot;
    $isEvent = $message->author === \App\Enums\MessageAuthor::System || $message->ticket_event !== null;
    $date = $message->created_at->copy()->timezone('Europe/Moscow');
    $startsNewDay = $previousMessage === null || ! $date->isSameDay($previousMessage->created_at->copy()->timezone('Europe/Moscow'));
    $startsNewGroup = $startsNewDay || $previousMessage->author !== $message->author
        || $previousMessage->author === \App\Enums\MessageAuthor::System || $previousMessage->ticket_event !== null;
    $deliveryLabel = $message->author === \App\Enums\MessageAuthor::Operator && $message->delivery_status === \App\Enums\DeliveryStatus::Pending
        ? 'Отправляется…' : $message->delivery_status?->label();
@endphp

@if ($startsNewDay)
    <div data-chat-date wire:key="date-{{ $attributes->get('wire:key', $message->id) }}" class="flex items-center gap-3 py-3 text-[11px] text-slate-500 dark:text-slate-400">
        <span class="h-px flex-1 bg-slate-200/70 dark:bg-slate-800"></span>
        <time datetime="{{ $date->toDateString() }}">{{ $date->isToday() ? 'Сегодня' : ($date->isYesterday() ? 'Вчера' : $date->locale('ru')->translatedFormat($date->year === now('Europe/Moscow')->year ? 'j F' : 'j F Y')) }}</time>
        <span class="h-px flex-1 bg-slate-200/70 dark:bg-slate-800"></span>
    </div>
@endif

<article {{ $attributes->class(['flex min-w-0', 'my-3 justify-center' => $isEvent, 'mt-3' => ! $isEvent && $startsNewGroup, 'mt-1' => ! $isEvent && ! $startsNewGroup, 'justify-start' => ! $isEvent && $isParticipant, 'justify-end' => ! $isEvent && ! $isParticipant]) }}>
    <div @class([
        'min-w-0 max-w-[88%] sm:max-w-[78%]',
        'flex flex-wrap items-baseline justify-center gap-x-2 text-center text-xs text-slate-500 dark:text-slate-400' => $isEvent,
        'rounded-xl border px-3 py-2 text-slate-900 dark:text-slate-100' => ! $isEvent,
        'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900' => ! $isEvent && $isParticipant,
        'border-transparent bg-slate-100 dark:bg-slate-800' => ! $isEvent && ! $isParticipant,
    ])>
        @if ($startsNewGroup || $isEvent)
            <div @if (! $isEvent) data-message-author @endif @class(['mb-1 text-[11px] font-medium text-slate-500 dark:text-slate-400', 'sr-only' => $isEvent])>
                <span class="inline-flex items-center gap-1">
                    @if ($isBot)
                        <svg aria-hidden="true" viewBox="0 0 16 16" class="size-3 fill-current"><path d="M7 0h2v3h4a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4V0ZM4 6v3h2V6H4Zm6 0v3h2V6h-2ZM5 11v1h6v-1H5Z"/></svg>
                    @endif
                    {{ $message->author->label() }}@if ($ticketId !== null)<span class="sr-only"> · Обращение #{{ $ticketId }}</span>@endif
                </span>
            </div>
        @endif
        <p @class(['whitespace-pre-wrap wrap-anywhere leading-relaxed', 'text-sm' => ! $isEvent])>{{ $message->body }}</p>
        <div @class(['flex flex-wrap gap-x-1.5 gap-y-1 text-[10px] tabular-nums text-slate-500 dark:text-slate-400', 'justify-center' => $isEvent, 'mt-1 justify-end' => ! $isEvent])>
            <time datetime="{{ $message->created_at->toIso8601String() }}" title="{{ $date->format('d.m.Y H:i') }} МСК">{{ $date->format('H:i') }}</time>
            @if ($message->direction === \App\Enums\MessageDirection::Outbound && $message->delivery_status !== null)
                <span data-delivery-status="{{ $message->delivery_status->value }}" title="Доставка: {{ $deliveryLabel }}" @class(['text-rose-600 dark:text-rose-400' => $message->delivery_status === \App\Enums\DeliveryStatus::Failed])>
                    <span aria-hidden="true">{{ match ($message->delivery_status) {
                        \App\Enums\DeliveryStatus::Sent => '✓',
                        \App\Enums\DeliveryStatus::Pending => $message->author === \App\Enums\MessageAuthor::Operator ? 'Отправляется…' : 'Ожидает',
                        \App\Enums\DeliveryStatus::Failed => 'Ошибка',
                        \App\Enums\DeliveryStatus::Cancelled => 'Отменено',
                    } }}</span>
                    <span class="sr-only">Доставка: {{ $deliveryLabel }}</span>
                </span>
            @endif
        </div>
        @if ($slot->isNotEmpty())
            <div class="mt-2 flex flex-wrap items-center gap-2">{{ $slot }}</div>
        @endif
    </div>
</article>
