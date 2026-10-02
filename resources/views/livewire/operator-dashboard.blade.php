<main class="mx-auto max-w-7xl px-6 py-8">
    <header class="mb-8 flex items-center justify-between gap-4">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-emerald-400">M-Social</p>
            <h1 class="mt-1 text-3xl font-semibold">Обращения поддержки</h1>
        </div>
        <form method="POST" action="{{ route('operator.logout') }}">
            @csrf
            <button type="submit" class="rounded-md border border-slate-600 px-3 py-2 text-sm hover:bg-slate-800">Выйти</button>
        </form>
    </header>

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
                            <span class="text-xs text-slate-400">{{ $ticket->status->value }}</span>
                        </div>
                        <p class="mt-1 truncate text-sm text-slate-400">{{ $ticket->escalation_reason }}</p>
                    </button>
                @empty
                    <p class="text-sm text-slate-400">Обращений нет.</p>
                @endforelse
            </div>
        </aside>

        <section class="min-h-96 rounded-xl border border-slate-700 bg-slate-900 p-5">
            @if ($selectedTicket !== null)
                <header class="border-b border-slate-700 pb-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-semibold">Обращение #{{ $selectedTicket->id }}</h2>
                            <p class="mt-1 text-sm text-slate-400">Статус: {{ $selectedTicket->status->value }} · Создано: {{ $selectedTicket->created_at->format('d.m.Y H:i') }}</p>
                            <p class="mt-1 text-sm text-slate-400">Участник: {{ $selectedTicket->participant->telegram_user_id }}</p>
                            <p class="mt-1 text-sm text-slate-400">Причина эскалации: {{ $selectedTicket->escalation_reason ?? 'не указана' }}</p>
                            @if ($selectedTicket->status === \App\Enums\TicketStatus::Closed)
                                <p class="mt-1 text-sm text-slate-400">Закрыто: {{ $selectedTicket->closed_at?->format('d.m.Y H:i') }} · Причина: {{ $selectedTicket->close_reason?->value }}</p>
                            @endif
                        </div>
                        @if ($selectedTicket->status !== \App\Enums\TicketStatus::Closed)
                            <button wire:click="closeTicket" type="button" class="rounded-md border border-rose-500 px-3 py-2 text-sm text-rose-300 hover:bg-rose-950">Закрыть обращение</button>
                        @endif
                    </div>
                </header>

                <div class="space-y-3 py-5">
                    @foreach ($selectedTicket->messages as $message)
                        <article wire:key="message-{{ $message->id }}" class="rounded-lg border border-slate-700 p-3">
                            <div class="flex flex-wrap justify-between gap-2 text-xs text-slate-400">
                                <span>{{ $message->author->value }}</span>
                                <span>{{ $message->created_at->format('d.m.Y H:i') }}</span>
                            </div>
                            <p class="mt-2 whitespace-pre-wrap text-sm">{{ $message->body }}</p>
                            @if ($message->direction->value === 'outbound')
                                <p class="mt-2 text-xs text-slate-400">Доставка: {{ $message->delivery_status?->value }}</p>
                                @if ($selectedTicket->status !== \App\Enums\TicketStatus::Closed && $message->delivery_status === \App\Enums\DeliveryStatus::Failed && $message->operator_id === auth()->id())
                                    <p class="mt-1 text-xs text-rose-300">Ошибка: {{ $message->last_delivery_error }}</p>
                                    <button wire:click="retryDelivery({{ $message->id }})" type="button" class="mt-2 rounded-md border border-slate-500 px-2 py-1 text-xs hover:bg-slate-800">Повторить отправку</button>
                                @endif
                            @endif
                        </article>
                    @endforeach
                </div>

                @if ($selectedTicket->status === \App\Enums\TicketStatus::Open)
                    <form wire:submit="sendReply" class="border-t border-slate-700 pt-4">
                        <label class="block text-sm font-medium" for="replyBody">Ответ участнику</label>
                        <textarea wire:model="replyBody" id="replyBody" rows="4" class="mt-2 w-full rounded-md border border-slate-600 bg-slate-800 p-3" maxlength="4000"></textarea>
                        @error('replyBody')
                            <p class="mt-1 text-sm text-rose-300">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="mt-3 rounded-md bg-emerald-500 px-4 py-2 font-medium text-slate-950 hover:bg-emerald-400" wire:loading.attr="disabled">Отправить ответ</button>
                    </form>
                @elseif ($selectedTicket->status === \App\Enums\TicketStatus::WaitingForUser)
                    <p class="border-t border-slate-700 pt-4 text-sm text-slate-400">Ожидается подтверждение участника.</p>
                @else
                    <p class="border-t border-slate-700 pt-4 text-sm text-slate-400">Обращение закрыто и доступно только для просмотра.</p>
                @endif
            @else
                <p class="text-slate-400">Выберите обращение из очереди.</p>
            @endif
        </section>
    </div>
</main>
