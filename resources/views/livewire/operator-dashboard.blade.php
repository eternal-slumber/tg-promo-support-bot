<main data-operator-dashboard wire:poll.15s.visible class="mx-auto max-w-[1440px] px-3 py-3 sm:px-4 md:flex md:h-dvh md:flex-col lg:px-5">
    <header class="mb-2 flex shrink-0 flex-wrap items-center justify-between gap-2">
        <h1 class="text-base font-semibold tracking-tight">Обращения поддержки</h1>
        <div class="flex items-center gap-1.5">
            <span class="mr-2 hidden items-center gap-1.5 text-[11px] text-slate-500 sm:inline-flex dark:text-slate-400"><span class="size-1.5 rounded-full bg-slate-400"></span>автообновление</span>
            <button data-theme-toggle type="button" aria-label="Переключить цветовую тему" class="flex size-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-200 dark:text-slate-400 dark:hover:bg-slate-800">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-4 dark:hidden"><path stroke-linecap="round" stroke-linejoin="round" d="M20.9 13.1A9 9 0 0 1 10.9 3.1a9 9 0 1 0 10 10Z"/></svg>
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="hidden size-4 dark:block"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>
                <span class="sr-only dark:hidden">Тёмная тема</span><span class="sr-only hidden dark:inline">Светлая тема</span>
            </button>
            <form method="POST" action="{{ route('operator.logout') }}">
                @csrf
                <button type="submit" class="rounded-lg px-2.5 py-1.5 text-xs text-slate-500 hover:bg-slate-200 dark:text-slate-400 dark:hover:bg-slate-800">Выйти</button>
            </form>
        </div>
    </header>

    <div data-dashboard-toolbar class="mb-2 flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <nav aria-label="Разделы панели" class="flex shrink-0 items-center gap-1">
            @foreach (['tickets' => 'Обращения', 'bot' => 'Ответы бота', 'statistics' => 'Статистика'] as $value => $label)
                <button wire:click="selectSection('{{ $value }}')" wire:key="section-{{ $value }}" type="button" aria-current="{{ $section === $value ? 'page' : 'false' }}" @class(['border-b-2 px-3 py-2 text-xs transition-colors', 'border-emerald-600 font-medium text-slate-900 dark:border-emerald-500 dark:text-slate-100' => $section === $value, 'border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100' => $section !== $value])>{{ $label }}</button>
            @endforeach
            <span wire:loading.delay role="status" class="sr-only">Обновление…</span>
        </nav>
        <dl aria-label="Статистика поддержки" class="ml-auto flex w-fit max-w-full flex-wrap items-center gap-x-4 gap-y-1 border-l border-slate-300 py-1.5 pl-4 text-[11px] dark:border-slate-700">
            <div class="flex items-baseline gap-1.5 whitespace-nowrap">
                <dt class="order-2 text-slate-500 dark:text-slate-400">Ответов ботом</dt>
                <dd class="font-medium tabular-nums">{{ $statistics['bot_resolved'] }}</dd>
            </div>
            <div class="flex items-baseline gap-1.5 whitespace-nowrap">
                <dt class="order-2 text-slate-500 dark:text-slate-400">Передано оператору</dt>
                <dd class="font-medium tabular-nums">{{ $statistics['escalated'] }}</dd>
            </div>
            <div class="flex items-baseline gap-1.5 whitespace-nowrap">
                <dt class="order-2 text-slate-500 dark:text-slate-400">Средний ответ</dt>
                <dd class="font-medium tabular-nums">{{ $statistics['average_operator_response_seconds'] === null ? 'Нет ответов' : number_format($statistics['average_operator_response_seconds'] / 60, 1, ',', ' ').' мин' }}</dd>
            </div>
        </dl>
    </div>

    @error('ticket')<p role="alert" class="mb-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
    @error('botReply')<p role="alert" class="mb-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror

    @if ($section === 'tickets')
        <div wire:key="tickets-workspace" class="grid min-h-0 min-w-0 rounded-xl border border-slate-200 bg-white md:flex-1 md:grid-cols-[13.5rem_minmax(0,1fr)] md:grid-rows-[auto_minmax(0,1fr)] md:overflow-hidden xl:grid-cols-[14rem_minmax(0,1fr)_13rem] xl:grid-rows-[minmax(0,1fr)] dark:border-slate-700 dark:bg-slate-900">
            <aside aria-label="Список обращений" class="flex min-h-0 min-w-0 flex-col border-b border-slate-200 md:col-start-1 md:row-span-2 md:row-start-1 md:border-r md:border-b-0 xl:row-span-1 dark:border-slate-700">
                <header class="shrink-0 border-b border-slate-200 p-3 dark:border-slate-700">
                    <h2 class="mb-2 text-sm font-semibold">{{ $filter === 'active' ? 'Активная очередь' : ($filter === 'closed' ? 'Закрытые обращения' : 'Все обращения') }}</h2>
                    <div class="flex gap-2 text-xs">
                        @foreach (['active' => 'Активные', 'closed' => 'Закрытые', 'all' => 'Все'] as $value => $label)
                            <button wire:click="selectFilter('{{ $value }}')" wire:key="filter-{{ $value }}" type="button" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}" @class(['min-w-0 flex-1 border-b-2 px-1 py-1.5 text-[11px]', 'border-emerald-600 font-medium text-slate-900 dark:border-emerald-500 dark:text-white' => $filter === $value, 'border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white' => $filter !== $value])>{{ $label }}</button>
                        @endforeach
                    </div>
                </header>
                <div class="max-h-72 flex-1 divide-y divide-slate-100 overflow-y-auto md:max-h-none dark:divide-slate-800">
                    @forelse ($tickets as $ticket)
                        <button wire:click="selectTicket({{ $ticket->id }})" wire:key="ticket-{{ $ticket->id }}" type="button" aria-pressed="{{ $selectedTicket?->id === $ticket->id ? 'true' : 'false' }}" @class(['block w-full border-l-2 px-3 py-2.5 text-left transition-colors', 'border-l-emerald-600 bg-slate-100 dark:border-l-emerald-500 dark:bg-slate-800' => $selectedTicket?->id === $ticket->id, 'border-l-transparent hover:bg-slate-50 dark:hover:bg-slate-800/60' => $selectedTicket?->id !== $ticket->id])>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold">#{{ $ticket->id }}</span>
                                <time class="text-[10px] tabular-nums text-slate-500 dark:text-slate-400" title="{{ $ticket->created_at->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК">{{ $ticket->created_at->copy()->timezone('Europe/Moscow')->format('d.m H:i') }}</time>
                            </div>
                            <p class="mt-1 line-clamp-2 text-[11px] leading-4 wrap-anywhere text-slate-500 dark:text-slate-400" title="{{ $ticket->escalationReasonLabel() }}">{{ $ticket->status->label() }} · {{ $ticket->escalationReasonLabel() }}</p>
                        </button>
                    @empty
                        <p class="px-3 py-8 text-center text-sm text-slate-500 dark:text-slate-400">Обращений нет.</p>
                    @endforelse
                </div>
                <x-cursor-navigation :paginator="$tickets" name="ticketsCursor" label="Страницы обращений" previous-label="Новые обращения" next-label="Старые обращения" latest-label="Последние обращения" class="border-t border-slate-200 p-3 dark:border-slate-700" />
            </aside>

            <aside aria-label="Сведения и действия обращения" class="grid min-h-0 min-w-0 gap-3 overflow-y-auto border-b border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_auto] md:col-start-2 md:row-start-1 md:max-h-40 xl:col-start-3 xl:block xl:max-h-none xl:border-b-0 xl:border-l dark:border-slate-700">
                <h2 class="sr-only text-xs font-semibold xl:not-sr-only">Сведения об обращении</h2>
                @if ($selectedTicket !== null)
                    <dl class="grid min-w-0 gap-x-4 gap-y-2 text-[11px] leading-4 sm:grid-cols-2 xl:mt-3 xl:grid-cols-1 xl:gap-y-3">
                        <div class="grid grid-cols-[4rem_minmax(0,1fr)] gap-x-2"><dt class="text-slate-500 dark:text-slate-400">Статус: </dt><dd>{{ $selectedTicket->status->label() }}</dd></div>
                        <div class="grid grid-cols-[4rem_minmax(0,1fr)] gap-x-2"><dt class="text-slate-500 dark:text-slate-400">Создано: </dt><dd class="tabular-nums">{{ $selectedTicket->created_at->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</dd></div>
                        <div class="grid grid-cols-[4rem_minmax(0,1fr)] gap-x-2 sm:col-span-2 xl:col-span-1"><dt class="text-slate-500 dark:text-slate-400">Причина: </dt><dd class="wrap-anywhere">{{ $selectedTicket->escalationReasonLabel() }}</dd></div>
                        @if ($selectedTicket->status === \App\Enums\TicketStatus::Closed)
                            <div class="grid grid-cols-[4rem_minmax(0,1fr)] gap-x-2"><dt class="text-slate-500 dark:text-slate-400">Закрыто: </dt><dd class="tabular-nums">{{ $selectedTicket->closed_at?->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</dd></div>
                            <div class="grid grid-cols-[4rem_minmax(0,1fr)] gap-x-2"><dt class="text-slate-500 dark:text-slate-400">Способ закрытия: </dt><dd>{{ $selectedTicket->close_reason?->label() ?? 'Не указан' }}</dd></div>
                        @endif
                    </dl>
                    @if ($selectedTicket->status !== \App\Enums\TicketStatus::Closed)
                        <div class="flex flex-wrap items-start gap-2 sm:flex-col xl:mt-4 xl:border-t xl:border-slate-200 xl:pt-3 dark:xl:border-slate-700">
                            @if ($selectedTicket->status === \App\Enums\TicketStatus::Open)
                                <button wire:click="resolveTicket" type="button" class="rounded-[10px] border border-slate-300 px-2.5 py-2 text-[11px] font-medium hover:bg-slate-50 sm:w-full dark:border-slate-600 dark:hover:bg-slate-800" wire:loading.attr="disabled">Отметить решённым</button>
                            @endif
                            <button wire:click="closeTicket" type="button" class="rounded-[10px] border border-slate-200 px-2.5 py-2 text-[11px] text-slate-600 hover:bg-slate-50 sm:w-full dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" wire:loading.attr="disabled">Закрыть обращение</button>
                        </div>
                    @endif
                @else
                    <p class="text-xs text-slate-500 xl:mt-4 dark:text-slate-400">Статус и действия появятся после выбора обращения.</p>
                @endif
            </aside>

            <section aria-label="Переписка обращения" x-data="{ sendingBody: '' }" class="flex h-[36rem] min-h-0 min-w-0 flex-col overflow-hidden bg-slate-50 md:col-start-2 md:row-start-2 md:h-full xl:row-start-1 dark:bg-slate-950/50">
                @if ($selectedTicket !== null)
                    @php
                        $unfinishedReply = $messages->first(fn ($message) => $message->author === \App\Enums\MessageAuthor::Operator
                            && $message->direction === \App\Enums\MessageDirection::Outbound
                            && in_array($message->delivery_status, [\App\Enums\DeliveryStatus::Pending, \App\Enums\DeliveryStatus::Failed], true));
                    @endphp
                    <header class="flex shrink-0 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
                        <div>
                            <h2 class="text-sm font-semibold">Обращение #{{ $selectedTicket->id }}</h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Участник: {{ $selectedTicket->participant->telegram_user_id }}</p>
                        </div>
                    </header>
                    <div data-ticket-chat data-latest-message-id="{{ $messages->max('id') ?? 0 }}" wire:key="conversation-{{ $selectedTicket->id }}" class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4 [overflow-anchor:none]">
                        @if ($contextMessages->isNotEmpty())
                            <details wire:ignore.self wire:key="context-panel-{{ $selectedTicket->id }}" aria-label="Контекст до обращения" class="mb-4 text-slate-500 dark:text-slate-400">
                                <summary class="cursor-pointer py-1 text-xs">Контекст до обращения</summary>
                                <div class="mt-2 border-l border-slate-200 py-2 pl-3 dark:border-slate-700">
                                    @php($previousContextMessage = null)
                                    @foreach ($contextMessages as $contextMessage)
                                        <x-message-bubble :message="$contextMessage" :previous-message="$previousContextMessage" wire:key="context-{{ $selectedTicket->id }}-{{ $contextMessage->id }}" />
                                        @php($previousContextMessage = $contextMessage)
                                    @endforeach
                                    <x-cursor-navigation :paginator="$contextMessages" name="contextCursor" label="Страницы контекста" previous-label="Раньше" next-label="Позже" />
                                </div>
                            </details>
                        @endif
                        <p class="mb-4 text-center text-[10px] text-slate-400 dark:text-slate-500">Обращение №{{ $selectedTicket->id }} создано</p>
                        <div>
                            @php($previousMessage = null)
                            @foreach ($messages->reverse() as $message)
                                <x-message-bubble :message="$message" :ticket-id="$selectedTicket->id" :previous-message="$previousMessage" wire:key="message-{{ $message->id }}">
                                    @if ($message->direction === \App\Enums\MessageDirection::Outbound && $message->author === \App\Enums\MessageAuthor::Operator && in_array($message->delivery_status, [\App\Enums\DeliveryStatus::Pending, \App\Enums\DeliveryStatus::Failed], true))
                                        @if ($message->delivery_status === \App\Enums\DeliveryStatus::Failed)
                                            <p class="w-full text-xs text-rose-600 dark:text-rose-400">Ошибка: {{ $message->last_delivery_error }}</p>
                                        @endif
                                        @if ($selectedTicket->status === \App\Enums\TicketStatus::Closed)
                                            @if ($message->delivery_status === \App\Enums\DeliveryStatus::Failed)
                                                <button wire:click="retryDelivery({{ $message->id }})" type="button" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700" wire:loading.attr="disabled">Повторить отправку</button>
                                            @endif
                                            <button wire:click="cancelDelivery({{ $message->id }})" type="button" class="rounded-lg px-2.5 py-1.5 text-xs text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-700" wire:loading.attr="disabled">Отменить доставку</button>
                                        @endif
                                    @endif
                                </x-message-bubble>
                                @if ($message->author === \App\Enums\MessageAuthor::Operator && $message->direction === \App\Enums\MessageDirection::Outbound && $message->delivery_status === \App\Enums\DeliveryStatus::Pending)
                                    <span wire:key="delivery-poll-{{ $message->id }}" wire:poll.1s="refreshOperatorReply({{ $message->id }})" class="hidden" aria-hidden="true"></span>
                                @endif
                                @php($previousMessage = $message)
                            @endforeach
                        </div>
                        <article data-local-reply wire:loading.flex wire:target="sendReply" style="display: none" role="status" class="mt-3 flex min-w-0 justify-end">
                            <div class="min-w-0 max-w-[88%] rounded-xl bg-slate-100 px-3 py-2 text-slate-900 sm:max-w-[78%] dark:bg-slate-800 dark:text-slate-100">
                                @if ($previousMessage === null || $previousMessage->author !== \App\Enums\MessageAuthor::Operator || $previousMessage->ticket_event !== null || ! $previousMessage->created_at->copy()->timezone('Europe/Moscow')->isToday())
                                    <p class="mb-1 text-[11px] font-medium text-slate-500 dark:text-slate-400">Оператор</p>
                                @endif
                                <p x-text="sendingBody" class="text-sm leading-relaxed whitespace-pre-wrap wrap-anywhere"></p>
                                <p class="mt-1 text-right text-[10px] text-slate-500 dark:text-slate-400">Отправляется…</p>
                            </div>
                        </article>
                    </div>
                    <footer class="shrink-0 border-t border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
                        @if ($selectedTicket->status !== \App\Enums\TicketStatus::Closed)
                            @if ($selectedTicket->status === \App\Enums\TicketStatus::Resolved)
                                <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">Обращение отмечено решённым и будет закрыто автоматически, если переписка не продолжится.</p>
                            @endif
                            <form x-data="operatorReplyComposer" wire:key="reply-composer-{{ $selectedTicket->id }}" data-reply-blocked="{{ $hasUnfinishedReply ? 'true' : 'false' }}" x-on:submit.prevent="submit()">
                                <label for="replyBody" class="sr-only">Ответ участнику</label>
                                <div class="flex items-end gap-1 rounded-xl border border-slate-200 bg-slate-50 p-1 focus-within:border-emerald-500 focus-within:ring-1 focus-within:ring-emerald-500 dark:border-slate-700 dark:bg-slate-800">
                                    <textarea wire:model="replyBody" x-ref="reply" x-on:keydown="onKeydown($event)" x-on:input="resize()" x-on:resize.window="resize()" x-bind:style="{ height, overflowY: overflow }" x-bind:disabled="submitting || @js($hasUnfinishedReply)" id="replyBody" rows="1" placeholder="Напишите ответ…" class="min-w-0 flex-1 resize-none border-0 bg-transparent px-2 py-2 text-sm leading-5 focus:outline-none disabled:opacity-60" @disabled($hasUnfinishedReply)></textarea>
                                    <button type="submit" aria-label="Отправить ответ" class="shrink-0 rounded-[10px] bg-emerald-600 px-3 py-2 text-xs font-medium leading-5 text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50" x-bind:disabled="submitting || @js($hasUnfinishedReply) || !$wire.replyBody.trim()" @disabled($hasUnfinishedReply)>Отправить</button>
                                </div>
                                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500 dark:text-slate-400">
                                    <span wire:loading.inline-flex wire:target="sendReply" style="display: none" role="status">Отправляется…</span>
                                    @if ($unfinishedReply !== null)
                                        <span role="status" @class(['text-rose-600 dark:text-rose-400' => $unfinishedReply->delivery_status === \App\Enums\DeliveryStatus::Failed])>{{ $unfinishedReply->delivery_status === \App\Enums\DeliveryStatus::Pending ? 'Отправляется…' : 'Не удалось отправить' }}</span>
                                        @if ($unfinishedReply->delivery_status === \App\Enums\DeliveryStatus::Failed)
                                            <button wire:click="retryDelivery({{ $unfinishedReply->id }})" type="button" aria-label="Повторить отправку" class="rounded px-1 py-0.5 font-medium text-slate-700 hover:bg-slate-100 disabled:opacity-50 dark:text-slate-200 dark:hover:bg-slate-800" wire:loading.attr="disabled">Повторить</button>
                                        @endif
                                        <button wire:click="cancelDelivery({{ $unfinishedReply->id }})" type="button" aria-label="Отменить доставку" class="rounded px-1 py-0.5 hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-800" wire:loading.attr="disabled">Отменить</button>
                                    @else
                                        <span wire:loading.remove wire:target="sendReply">Enter — отправить · Shift+Enter — новая строка</span>
                                    @endif
                                </div>
                                @error('replyBody')<p role="alert" class="mt-2 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                            </form>
                        @else
                            <p class="text-xs leading-relaxed text-slate-500 dark:text-slate-400">Обращение закрыто. Ранее созданные ответы продолжают доставляться; их можно повторить или отменить отдельно.</p>
                        @endif
                    </footer>
                @else
                    <div class="flex flex-1 flex-col items-center justify-center gap-2 p-8 text-center">
                        <span class="text-2xl text-slate-400 dark:text-slate-500" aria-hidden="true">↔</span>
                        <h2 class="text-sm font-medium">Переписка с участником</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Выберите обращение из очереди.</p>
                    </div>
                @endif
            </section>

        </div>
    @elseif ($section === 'bot')
        <div wire:key="bot-workspace" class="grid min-h-0 min-w-0 rounded-xl border border-slate-200 bg-white md:flex-1 md:grid-cols-[13.5rem_minmax(0,1fr)] md:grid-rows-[auto_minmax(0,1fr)] md:overflow-hidden xl:grid-cols-[14rem_minmax(0,1fr)_14rem] xl:grid-rows-[minmax(0,1fr)] dark:border-slate-700 dark:bg-slate-900">
            <aside aria-label="Список ответов бота" class="flex min-h-0 min-w-0 flex-col border-b border-slate-200 md:col-start-1 md:row-span-2 md:row-start-1 md:border-r md:border-b-0 xl:row-span-1 dark:border-slate-700">
                <header class="shrink-0 border-b border-slate-200 p-3 dark:border-slate-700"><h2 class="text-sm font-semibold">Ответы бота</h2><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Самостоятельные ответы и отказы</p></header>
                <div class="max-h-72 flex-1 divide-y divide-slate-100 overflow-y-auto md:max-h-none dark:divide-slate-800">
                    @forelse ($botReplies as $botReply)
                        <button wire:click="selectBotReply({{ $botReply->id }})" wire:key="bot-reply-{{ $botReply->id }}" type="button" aria-pressed="{{ $selectedBotReply?->id === $botReply->id ? 'true' : 'false' }}" @class(['block w-full border-l-2 px-3 py-2.5 text-left', 'border-l-emerald-600 bg-slate-100 dark:border-l-emerald-500 dark:bg-slate-800' => $selectedBotReply?->id === $botReply->id, 'border-l-transparent hover:bg-slate-50 dark:hover:bg-slate-800/60' => $selectedBotReply?->id !== $botReply->id])>
                            <p class="line-clamp-2 text-xs font-medium wrap-anywhere">{{ $botReply->sourceMessage->body }}</p>
                            <p class="mt-1 truncate text-[11px] text-slate-500 dark:text-slate-400">{{ $botReply->body }}</p>
                            <div class="mt-1.5 flex flex-wrap justify-between gap-1 text-[10px] text-slate-500 dark:text-slate-400"><time>{{ $botReply->created_at->copy()->timezone('Europe/Moscow')->format('d.m H:i') }}</time><span>{{ $botReply->delivery_status?->label() ?? 'Не указана' }}</span></div>
                        </button>
                    @empty
                        <p class="px-3 py-8 text-center text-sm text-slate-500 dark:text-slate-400">Самостоятельных ответов бота пока нет.</p>
                    @endforelse
                </div>
                <x-cursor-navigation :paginator="$botReplies" name="botRepliesCursor" label="Страницы ответов бота" previous-label="Новые ответы" next-label="Старые ответы" latest-label="Последние ответы" class="border-t border-slate-200 p-3 dark:border-slate-700" />
            </aside>
            <aside aria-label="Основание ответа бота" class="grid min-h-0 min-w-0 gap-3 overflow-y-auto border-b border-slate-200 p-3 sm:grid-cols-2 md:col-start-2 md:row-start-1 md:max-h-40 xl:col-start-3 xl:block xl:max-h-none xl:border-b-0 xl:border-l dark:border-slate-700">
                <h2 class="sr-only text-xs font-semibold xl:not-sr-only">Основание ответа</h2>
                @if ($selectedBotReply !== null)
                    @php($decision = $selectedBotReply->sourceMessage->decision)
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-[11px] xl:mt-3 xl:grid-cols-1 xl:gap-y-3 xl:text-xs">
                        <div><dt class="text-slate-500 dark:text-slate-400">Решение</dt><dd class="mt-1 font-medium">{{ $decision->type === \App\Enums\SupportDecisionType::Answer ? 'Ответ по правилам' : 'Отказ' }} <span class="font-normal text-slate-500 dark:text-slate-400">({{ $decision->type->value }})</span></dd></div>
                        <div><dt class="text-slate-500 dark:text-slate-400">Причина</dt><dd class="mt-1 wrap-anywhere">{{ $decision->reason }}</dd></div>
                        <div><dt class="text-slate-500 dark:text-slate-400">Доставка</dt><dd class="mt-1">{{ $selectedBotReply->delivery_status?->label() ?? 'Не указана' }}</dd></div>
                        @if ($selectedBotReply->delivered_at !== null)
                            <div><dt class="text-slate-500 dark:text-slate-400">Доставлено</dt><dd class="mt-1">{{ $selectedBotReply->delivered_at->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</dd></div>
                        @endif
                    </dl>
                    <section class="xl:mt-4 xl:border-t xl:border-slate-200 xl:pt-4 dark:xl:border-slate-700">
                        <h3 class="text-xs font-semibold">Пункты правил</h3>
                        @forelse ($decision->structured_output['evidence'] ?? [] as $index => $evidence)
                            <blockquote wire:key="evidence-{{ $decision->id }}-{{ $index }}" class="mt-3 border-l-2 border-slate-300 pl-3 text-xs leading-relaxed text-slate-600 dark:border-slate-600 dark:text-slate-300"><p class="mb-1 font-medium">Пункт {{ $evidence['rule_id'] ?? 'не указан' }}</p>{{ $evidence['quote'] ?? '' }}</blockquote>
                        @empty
                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Ссылки на пункты правил отсутствуют.</p>
                        @endforelse
                    </section>
                @else
                    <p class="text-xs text-slate-500 xl:mt-4 dark:text-slate-400">Здесь появятся решение и ссылки на правила.</p>
                @endif
            </aside>
            <section aria-label="Диалог с ботом" class="flex h-[36rem] min-h-0 min-w-0 flex-col overflow-hidden bg-slate-50 md:col-start-2 md:row-start-2 md:h-full xl:row-start-1 dark:bg-slate-950/50">
                @if ($selectedBotReply !== null)
                    <header class="shrink-0 border-b border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900"><h2 class="text-sm font-semibold">Диалог с ботом</h2><p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Участник: {{ $selectedBotReply->participant->telegram_user_id }}</p></header>
                    <div class="min-h-0 flex-1 overflow-y-auto p-4">
                        <x-message-bubble :message="$selectedBotReply->sourceMessage" wire:key="bot-question-{{ $selectedBotReply->source_message_id }}" />
                        <x-message-bubble :message="$selectedBotReply" :previous-message="$selectedBotReply->sourceMessage" wire:key="bot-answer-{{ $selectedBotReply->id }}">
                            @if ($selectedBotReply->delivery_status === \App\Enums\DeliveryStatus::Failed)
                                <p class="text-xs text-rose-600 dark:text-rose-400">Ошибка: {{ $selectedBotReply->last_delivery_error }}</p>
                            @endif
                        </x-message-bubble>
                    </div>
                    <footer class="shrink-0 border-t border-slate-200 px-4 py-3 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">Только просмотр · самостоятельный ответ без обращения</footer>
                @else
                    <div class="flex flex-1 items-center justify-center p-8 text-center text-sm text-slate-500 dark:text-slate-400">Выберите ответ бота, чтобы увидеть вопрос и ответ участнику.</div>
                @endif
            </section>
        </div>
    @else
        <section wire:key="statistics-workspace" aria-label="Подробная статистика" class="min-h-0 rounded-xl border border-slate-200 bg-white p-5 md:flex-1 md:overflow-y-auto dark:border-slate-700 dark:bg-slate-900">
            <h2 class="text-sm font-semibold">Статистика поддержки</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Подготовленные ответы учитываются отдельно от успешно доставленных.</p>
            <dl class="mt-5 divide-y divide-slate-200 text-sm dark:divide-slate-700">
                @foreach (['bot_resolved' => 'Доставлено ответов по правилам без оператора', 'bot_prepared' => 'Подготовлено ответов по правилам', 'bot_pending' => 'Ответы бота: ожидают отправки', 'bot_failed' => 'Ответы бота: ошибка отправки', 'bot_cancelled' => 'Ответы бота: отменены', 'escalated' => 'Передано операторам', 'operator_cancelled' => 'Отменено ответов оператора'] as $key => $label)
                    <div wire:key="statistic-{{ $key }}" class="flex items-center justify-between gap-4 py-3"><dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="font-semibold tabular-nums">{{ $statistics[$key] }}</dd></div>
                @endforeach
                <div class="flex items-center justify-between gap-4 py-3"><dt class="text-slate-500 dark:text-slate-400">Среднее время первого ответа оператора</dt><dd class="font-semibold tabular-nums">{{ $statistics['average_operator_response_seconds'] === null ? 'Нет ответов' : number_format($statistics['average_operator_response_seconds'] / 60, 1, ',', ' ').' мин' }}</dd></div>
            </dl>
        </section>
    @endif
</main>
