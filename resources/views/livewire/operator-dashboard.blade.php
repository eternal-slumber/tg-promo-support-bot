<main wire:poll.15s.visible class="mx-auto max-w-7xl px-6 py-8">
    <header class="mb-8 flex items-center justify-between gap-4">
        <div>
            <h1 class="mt-1 text-3xl font-semibold">Обращения поддержки</h1>
        </div>
        <form method="POST" action="{{ route('operator.logout') }}">
            @csrf
            <button type="submit" class="rounded-md border border-slate-600 px-3 py-2 text-sm hover:bg-slate-800">Выйти</button>
        </form>
    </header>

    <dl aria-label="Статистика поддержки" class="mb-6 grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-700 bg-slate-900 p-4">
            <dt class="text-sm text-slate-400">Доставлено ответов по правилам без оператора</dt>
            <dd class="mt-2">
                <span class="text-2xl font-semibold">{{ $statistics['bot_resolved'] }}</span>
                <p class="mt-2 text-xs text-slate-400">Подготовлено: {{ $statistics['bot_prepared'] }}</p>
                <p class="mt-1 text-xs text-slate-400">Ожидают отправки: {{ $statistics['bot_pending'] }} · С ошибкой: {{ $statistics['bot_failed'] }} · Отменено: {{ $statistics['bot_cancelled'] }}</p>
            </dd>
        </div>
        <div class="rounded-xl border border-slate-700 bg-slate-900 p-4">
            <dt class="text-sm text-slate-400">Передано операторам</dt>
            <dd class="mt-2 text-2xl font-semibold">{{ $statistics['escalated'] }}</dd>
        </div>
        <div class="rounded-xl border border-slate-700 bg-slate-900 p-4">
            <dt class="text-sm text-slate-400">Среднее время первого ответа оператора</dt>
            <dd class="mt-2">
                <span class="text-2xl font-semibold">{{ $statistics['average_operator_response_seconds'] === null ? 'Нет ответов' : number_format($statistics['average_operator_response_seconds'] / 60, 1, ',', ' ').' мин' }}</span>
                <p class="mt-2 text-xs text-slate-400">От создания обращения до первой успешной отправки.</p>
                <p class="mt-1 text-xs text-slate-400">Отменено ответов оператора: {{ $statistics['operator_cancelled'] }}</p>
            </dd>
        </div>
    </dl>

    <div class="grid gap-6 lg:grid-cols-[20rem_1fr]">
        <aside class="rounded-xl border border-slate-700 bg-slate-900 p-4">
            <div class="flex gap-2 border-b border-slate-700 pb-3 text-sm">
                @foreach (['active' => 'Активные', 'closed' => 'Закрытые', 'all' => 'Все'] as $value => $label)
                    <button wire:click="selectFilter('{{ $value }}')" type="button" class="rounded px-2 py-1 {{ $filter === $value ? 'bg-emerald-500 font-medium text-slate-950' : 'text-slate-400 hover:bg-slate-800' }}">{{ $label }}</button>
                @endforeach
            </div>
            <h2 class="mt-4 font-semibold">
                {{ $filter === 'active' ? 'Активная очередь' : ($filter === 'closed' ? 'Закрытые обращения' : 'Все обращения') }}
            </h2>
            <div class="mt-4 space-y-2">
                @forelse ($tickets as $ticket)
                    <button wire:click="selectTicket({{ $ticket->id }})" wire:key="ticket-{{ $ticket->id }}" type="button" class="w-full rounded-lg border border-slate-700 p-3 text-left hover:border-emerald-400 {{ $selectedTicket?->id === $ticket->id ? 'border-emerald-400 bg-slate-800' : '' }}">
                        <div class="flex items-center justify-between gap-3">
                            <span class="font-medium">#{{ $ticket->id }}</span>
                            <span class="text-xs text-slate-400">{{ $ticket->status->label() }}</span>
                        </div>
                        <p class="mt-1 truncate text-sm text-slate-400">{{ $ticket->escalationReasonLabel() }}</p>
                    </button>
                @empty
                    <p class="text-sm text-slate-400">Обращений нет.</p>
                @endforelse
            </div>
            @if ($tickets->hasPages())
                <nav aria-label="Страницы обращений" class="mt-4 flex flex-wrap justify-between gap-2 text-sm">
                    @if ($cursor = $tickets->previousCursor())
                        <button wire:click="setPage('{{ $cursor->encode() }}', 'ticketsCursor')" wire:key="tickets-previous-{{ $cursor->encode() }}" type="button" class="rounded border border-slate-600 px-3 py-2 hover:bg-slate-800" wire:loading.attr="disabled">Новые обращения</button>
                    @endif
                    @if ($cursor = $tickets->nextCursor())
                        <button wire:click="setPage('{{ $cursor->encode() }}', 'ticketsCursor')" wire:key="tickets-next-{{ $cursor->encode() }}" type="button" class="rounded border border-slate-600 px-3 py-2 hover:bg-slate-800" wire:loading.attr="disabled">Старые обращения</button>
                    @endif
                    @if (! $tickets->onFirstPage())
                        <button wire:click="resetPage('ticketsCursor')" type="button" class="rounded border border-slate-600 px-3 py-2 hover:bg-slate-800" wire:loading.attr="disabled">Последние обращения</button>
                    @endif
                </nav>
            @endif
        </aside>

        <section class="min-h-96 rounded-xl border border-slate-700 bg-slate-900 p-5">
            @error('ticket')
                <p class="mb-3 text-sm text-rose-300">{{ $message }}</p>
            @enderror
            @if ($selectedTicket !== null)
                <header class="border-b border-slate-700 pb-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-semibold">Обращение #{{ $selectedTicket->id }}</h2>
                            <p class="mt-1 text-sm text-slate-400">Статус: {{ $selectedTicket->status->label() }}</p>
                            <p class="mt-1 text-sm text-slate-400">Создано: {{ $selectedTicket->created_at->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</p>
                            <p class="mt-1 text-sm text-slate-400">Участник: {{ $selectedTicket->participant->telegram_user_id }}</p>
                            <p class="mt-1 text-sm text-slate-400">Причина передачи оператору: {{ $selectedTicket->escalationReasonLabel() }}</p>
                            @if ($selectedTicket->status === \App\Enums\TicketStatus::Closed)
                                <p class="mt-1 text-sm text-slate-400">Закрыто: {{ $selectedTicket->closed_at?->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</p>
                                <p class="mt-1 text-sm text-slate-400">Способ закрытия: {{ $selectedTicket->close_reason?->label() ?? 'Не указан' }}</p>
                            @endif
                        </div>
                        @if ($selectedTicket->status !== \App\Enums\TicketStatus::Closed)
                            <button wire:click="closeTicket" type="button" class="rounded-md border border-rose-500 px-3 py-2 text-sm text-rose-300 hover:bg-rose-950">Закрыть обращение</button>
                        @endif
                    </div>
                </header>

                <div class="space-y-3 py-5">
                    @foreach ($messages->getCollection()->reverse() as $message)
                        <article wire:key="message-{{ $message->id }}" class="rounded-lg border border-slate-700 p-3">
                            <div class="flex flex-wrap justify-between gap-2 text-xs text-slate-400">
                                <span>{{ $message->author->label() }} · {{ $message->ticket_id === null ? 'Без обращения' : 'Обращение #'.$message->ticket_id }}</span>
                                <span>{{ $message->created_at->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</span>
                            </div>
                            <p class="mt-2 whitespace-pre-wrap text-sm">{{ $message->body }}</p>
                            @if ($message->direction->value === 'outbound')
                                <p class="mt-2 text-xs text-slate-400">Доставка: {{ $message->delivery_status?->label() }}</p>
                                @if ($message->ticket_id === $selectedTicket->id && $selectedTicket->status !== \App\Enums\TicketStatus::Closed && $message->author === \App\Enums\MessageAuthor::Operator && in_array($message->delivery_status, [\App\Enums\DeliveryStatus::Pending, \App\Enums\DeliveryStatus::Failed], true))
                                    @if ($message->delivery_status === \App\Enums\DeliveryStatus::Failed)
                                        <p class="mt-1 text-xs text-rose-300">Ошибка: {{ $message->last_delivery_error }}</p>
                                        <button wire:click="retryDelivery({{ $message->id }})" type="button" class="mt-2 rounded-md border border-slate-500 px-2 py-1 text-xs hover:bg-slate-800" wire:loading.attr="disabled">Повторить отправку</button>
                                    @endif
                                    <button wire:click="cancelDelivery({{ $message->id }})" type="button" class="mt-2 rounded-md border border-rose-500 px-2 py-1 text-xs text-rose-300 hover:bg-rose-950" wire:loading.attr="disabled">Отменить доставку</button>
                                @endif
                            @endif
                        </article>
                    @endforeach
                </div>

                @if ($messages->hasPages())
                    <nav aria-label="Страницы истории сообщений" class="mb-4 flex flex-wrap justify-between gap-2 text-sm">
                        @if ($cursor = $messages->nextCursor())
                            <button wire:click="setPage('{{ $cursor->encode() }}', 'messagesCursor')" wire:key="messages-next-{{ $cursor->encode() }}" type="button" class="rounded border border-slate-600 px-3 py-2 hover:bg-slate-800" wire:loading.attr="disabled">Раньше</button>
                        @endif
                        @if ($cursor = $messages->previousCursor())
                            <button wire:click="setPage('{{ $cursor->encode() }}', 'messagesCursor')" wire:key="messages-previous-{{ $cursor->encode() }}" type="button" class="rounded border border-slate-600 px-3 py-2 hover:bg-slate-800" wire:loading.attr="disabled">Позже</button>
                        @endif
                        @if (! $messages->onFirstPage())
                            <button wire:click="resetPage('messagesCursor')" type="button" class="rounded border border-slate-600 px-3 py-2 hover:bg-slate-800" wire:loading.attr="disabled">Последние сообщения</button>
                        @endif
                    </nav>
                @endif

                @if ($selectedTicket->status !== \App\Enums\TicketStatus::Closed)
                    @if ($selectedTicket->status === \App\Enums\TicketStatus::Resolved)
                        <p class="mb-3 text-sm text-slate-400">Обращение отмечено решённым и будет закрыто автоматически, если переписка не продолжится.</p>
                    @endif
                    <form wire:submit="sendReply" class="border-t border-slate-700 pt-4">
                        <label class="block text-sm font-medium" for="replyBody">Ответ участнику</label>
                        <textarea wire:model="replyBody" id="replyBody" rows="4" class="mt-2 w-full rounded-md border border-slate-600 bg-slate-800 p-3"></textarea>
                        @error('replyBody')
                            <p class="mt-1 text-sm text-rose-300">{{ $message }}</p>
                        @enderror
                        <div class="mt-3 flex flex-wrap gap-3">
                            <button type="submit" class="rounded-md bg-emerald-500 px-4 py-2 font-medium text-slate-950 hover:bg-emerald-400" wire:loading.attr="disabled">Отправить ответ</button>
                            <button wire:click="sendReply(true)" type="button" class="rounded-md border border-emerald-500 px-4 py-2 font-medium text-emerald-400 hover:bg-slate-800" wire:loading.attr="disabled">Отправить и решить</button>
                        </div>
                    </form>
                @else
                    <p class="border-t border-slate-700 pt-4 text-sm text-slate-400">Обращение закрыто и доступно только для просмотра.</p>
                @endif
            @else
                <p class="text-slate-400">Выберите обращение из очереди.</p>
            @endif
        </section>
    </div>
</main>
