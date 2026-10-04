<main data-operator-dashboard wire:poll.15s.visible class="mx-auto max-w-[1600px] px-3 py-3 sm:px-5 lg:px-6">
    <header class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold tracking-tight">Обращения поддержки</h1>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Рабочее место оператора</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="hidden items-center gap-1.5 text-xs text-slate-500 sm:inline-flex dark:text-slate-400"><span class="size-1.5 rounded-full bg-emerald-500"></span>Обновление каждые 15 сек.</span>
            <button data-theme-toggle type="button" aria-label="Переключить цветовую тему" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800">
                <span class="dark:hidden">Тёмная тема</span><span class="hidden dark:inline">Светлая тема</span>
            </button>
            <form method="POST" action="{{ route('operator.logout') }}">
                @csrf
                <button type="submit" class="rounded-lg px-3 py-2 text-xs text-slate-500 hover:bg-slate-200 dark:text-slate-400 dark:hover:bg-slate-800">Выйти</button>
            </form>
        </div>
    </header>

    <dl aria-label="Статистика поддержки" class="grid divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white text-xs sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-slate-700 dark:border-slate-700 dark:bg-slate-900">
        <div class="px-4 py-2.5">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Доставлено ответов по правилам без оператора</dt>
                <dd class="text-xl font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">{{ $statistics['bot_resolved'] }}</dd>
            </div>
            <p class="mt-1 text-[10px] text-slate-500 dark:text-slate-400">Подготовлено: {{ $statistics['bot_prepared'] }} · Ожидают отправки: {{ $statistics['bot_pending'] }} · С ошибкой: {{ $statistics['bot_failed'] }} · Отменено: {{ $statistics['bot_cancelled'] }}</p>
        </div>
        <div class="px-4 py-2.5">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Передано операторам</dt>
                <dd class="text-xl font-semibold tabular-nums">{{ $statistics['escalated'] }}</dd>
            </div>
            <p class="mt-1 text-[10px] text-slate-500 dark:text-slate-400">Отменено ответов оператора: {{ $statistics['operator_cancelled'] }}</p>
        </div>
        <div class="px-4 py-2.5">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Среднее время первого ответа оператора</dt>
                <dd class="shrink-0 text-lg font-semibold tabular-nums">{{ $statistics['average_operator_response_seconds'] === null ? 'Нет ответов' : number_format($statistics['average_operator_response_seconds'] / 60, 1, ',', ' ').' мин' }}</dd>
            </div>
            <p class="mt-1 text-[10px] text-slate-500 dark:text-slate-400">От создания обращения до первой успешной отправки.</p>
        </div>
    </dl>

    <nav aria-label="Разделы панели" class="my-3 flex items-center gap-1">
        @foreach (['tickets' => 'Обращения', 'bot' => 'Ответы бота', 'statistics' => 'Статистика'] as $value => $label)
            <button wire:click="selectSection('{{ $value }}')" wire:key="section-{{ $value }}" type="button" aria-current="{{ $section === $value ? 'page' : 'false' }}" @class(['rounded-lg px-4 py-2 text-sm transition-colors', 'bg-slate-900 font-medium text-white dark:bg-slate-100 dark:text-slate-900' => $section === $value, 'text-slate-500 hover:bg-slate-200 dark:text-slate-400 dark:hover:bg-slate-800' => $section !== $value])>{{ $label }}</button>
        @endforeach
        <span wire:loading.delay class="ml-auto text-xs text-slate-500 dark:text-slate-400">Обновление…</span>
    </nav>

    @error('ticket')<p role="alert" class="mb-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
    @error('botReply')<p role="alert" class="mb-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror

    @if ($section === 'tickets')
        <div wire:key="tickets-workspace" class="grid rounded-2xl border border-slate-200 bg-white shadow-xs lg:h-[calc(100dvh-14rem)] lg:min-h-[32rem] lg:grid-cols-[15rem_minmax(0,1fr)_16rem] lg:overflow-hidden dark:border-slate-700 dark:bg-slate-900">
            <aside aria-label="Список обращений" class="flex min-h-0 flex-col border-b border-slate-200 lg:border-r lg:border-b-0 dark:border-slate-700">
                <header class="border-b border-slate-200 p-4 dark:border-slate-700">
                    <h2 class="mb-3 text-sm font-semibold">{{ $filter === 'active' ? 'Активная очередь' : ($filter === 'closed' ? 'Закрытые обращения' : 'Все обращения') }}</h2>
                    <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-xs dark:bg-slate-800">
                        @foreach (['active' => 'Активные', 'closed' => 'Закрытые', 'all' => 'Все'] as $value => $label)
                            <button wire:click="selectFilter('{{ $value }}')" wire:key="filter-{{ $value }}" type="button" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}" @class(['flex-1 rounded-md px-2 py-1.5', 'bg-white font-medium text-slate-900 shadow-xs dark:bg-slate-700 dark:text-white' => $filter === $value, 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white' => $filter !== $value])>{{ $label }}</button>
                        @endforeach
                    </div>
                </header>
                <div class="max-h-72 flex-1 overflow-y-auto p-2 lg:max-h-none">
                    @forelse ($tickets as $ticket)
                        <button wire:click="selectTicket({{ $ticket->id }})" wire:key="ticket-{{ $ticket->id }}" type="button" aria-pressed="{{ $selectedTicket?->id === $ticket->id ? 'true' : 'false' }}" @class(['mb-1 w-full rounded-xl border p-3 text-left transition-colors', 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950' => $selectedTicket?->id === $ticket->id, 'border-transparent hover:bg-slate-50 dark:hover:bg-slate-800' => $selectedTicket?->id !== $ticket->id])>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold">#{{ $ticket->id }}</span>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $ticket->status->label() }}</span>
                            </div>
                            <p class="mt-1.5 truncate text-xs text-slate-500 dark:text-slate-400">{{ $ticket->escalationReasonLabel() }}</p>
                            <time class="mt-2 block text-[10px] text-slate-400 dark:text-slate-500">{{ $ticket->created_at->copy()->timezone('Europe/Moscow')->format('d.m H:i') }} МСК</time>
                        </button>
                    @empty
                        <p class="px-3 py-8 text-center text-sm text-slate-500 dark:text-slate-400">Обращений нет.</p>
                    @endforelse
                </div>
                <x-cursor-navigation :paginator="$tickets" name="ticketsCursor" label="Страницы обращений" previous-label="Новые обращения" next-label="Старые обращения" latest-label="Последние обращения" class="border-t border-slate-200 p-3 dark:border-slate-700" />
            </aside>

            <section aria-label="Переписка обращения" class="flex h-[36rem] min-h-0 min-w-0 flex-col overflow-hidden bg-slate-50 lg:h-full dark:bg-slate-950/50">
                @if ($selectedTicket !== null)
                    <header class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4 dark:border-slate-700 dark:bg-slate-900">
                        <div>
                            <h2 class="text-sm font-semibold">Обращение #{{ $selectedTicket->id }}</h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Участник: {{ $selectedTicket->participant->telegram_user_id }}</p>
                        </div>
                        <span class="rounded-full border border-slate-200 px-2.5 py-1 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">{{ $selectedTicket->status->label() }}</span>
                    </header>
                    <div data-ticket-chat data-latest-message-id="{{ $messages->max('id') ?? 0 }}" wire:key="conversation-{{ $selectedTicket->id }}" class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-5 [overflow-anchor:none] sm:px-6">
                        @if ($contextMessages->isNotEmpty())
                            <details wire:ignore.self wire:key="context-panel-{{ $selectedTicket->id }}" aria-label="Контекст до обращения" class="mb-5 rounded-xl border border-dashed border-slate-300 bg-slate-100 dark:border-slate-600 dark:bg-slate-800/50">
                                <summary class="cursor-pointer px-4 py-3 text-xs font-medium text-slate-600 dark:text-slate-300">Контекст до обращения <span class="ml-2 text-[10px] font-normal text-slate-500 dark:text-slate-400">Только просмотр</span></summary>
                                <div class="space-y-3 border-t border-dashed border-slate-300 p-4 dark:border-slate-600">
                                    @foreach ($contextMessages as $contextMessage)
                                        <x-message-bubble :message="$contextMessage" wire:key="context-{{ $selectedTicket->id }}-{{ $contextMessage->id }}" />
                                    @endforeach
                                    <x-cursor-navigation :paginator="$contextMessages" name="contextCursor" label="Страницы контекста" previous-label="Раньше" next-label="Позже" />
                                </div>
                            </details>
                        @endif
                        <p class="mb-5 text-center text-[10px] text-slate-400 dark:text-slate-500">Обращение №{{ $selectedTicket->id }} создано</p>
                        <div class="space-y-4">
                            @foreach ($messages->reverse() as $message)
                                <x-message-bubble :message="$message" :ticket-id="$selectedTicket->id" wire:key="message-{{ $message->id }}">
                                    @if ($message->direction === \App\Enums\MessageDirection::Outbound && $message->author === \App\Enums\MessageAuthor::Operator && in_array($message->delivery_status, [\App\Enums\DeliveryStatus::Pending, \App\Enums\DeliveryStatus::Failed], true))
                                        @if ($message->delivery_status === \App\Enums\DeliveryStatus::Failed)
                                            <p class="w-full text-xs text-rose-600 dark:text-rose-400">Ошибка: {{ $message->last_delivery_error }}</p>
                                            <button wire:click="retryDelivery({{ $message->id }})" type="button" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700" wire:loading.attr="disabled">Повторить отправку</button>
                                        @endif
                                        <button wire:click="cancelDelivery({{ $message->id }})" type="button" class="rounded-lg px-2.5 py-1.5 text-xs text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950" wire:loading.attr="disabled">Отменить доставку</button>
                                    @endif
                                </x-message-bubble>
                            @endforeach
                        </div>
                    </div>
                    <footer class="border-t border-slate-200 bg-white px-5 py-4 dark:border-slate-700 dark:bg-slate-900">
                        @if ($selectedTicket->status !== \App\Enums\TicketStatus::Closed)
                            @if ($selectedTicket->status === \App\Enums\TicketStatus::Resolved)
                                <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">Обращение отмечено решённым и будет закрыто автоматически, если переписка не продолжится.</p>
                            @endif
                            <form wire:submit="sendReply">
                                @if ($hasUnfinishedReply)
                                    <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">Дождитесь отправки предыдущего ответа или отмените его. Для ответа с ошибкой выберите «Повторить» или «Отменить».</p>
                                @endif
                                <label for="replyBody" class="mb-2 block text-xs font-medium">Ответ участнику</label>
                                <div class="flex items-end gap-2">
                                    <textarea wire:model="replyBody" id="replyBody" rows="2" placeholder="Напишите ответ…" class="min-w-0 flex-1 resize-y rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-800"></textarea>
                                    <button type="submit" class="shrink-0 rounded-xl bg-emerald-600 px-3 py-2.5 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50" wire:loading.attr="disabled" @disabled($hasUnfinishedReply)>Отправить ответ</button>
                                </div>
                                @error('replyBody')<p role="alert" class="mt-2 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                            </form>
                        @else
                            <p class="text-xs leading-relaxed text-slate-500 dark:text-slate-400">Обращение закрыто. Ранее созданные ответы продолжают доставляться; их можно повторить или отменить отдельно.</p>
                        @endif
                    </footer>
                @else
                    <div class="flex flex-1 flex-col items-center justify-center gap-2 p-8 text-center">
                        <span class="flex size-12 items-center justify-center rounded-2xl bg-slate-200 text-xl text-slate-500 dark:bg-slate-800 dark:text-slate-400" aria-hidden="true">↔</span>
                        <h2 class="text-sm font-medium">Переписка с участником</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Выберите обращение из очереди.</p>
                    </div>
                @endif
            </section>

            <aside aria-label="Сведения и действия обращения" class="min-h-0 overflow-y-auto border-t border-slate-200 p-5 lg:border-t-0 lg:border-l dark:border-slate-700">
                <h2 class="text-sm font-semibold">Сведения об обращении</h2>
                @if ($selectedTicket !== null)
                    <ul class="mt-5 space-y-4 text-xs text-slate-500 dark:text-slate-400">
                        <li>Статус: {{ $selectedTicket->status->label() }}</li>
                        <li>Создано: {{ $selectedTicket->created_at->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</li>
                        <li class="wrap-anywhere">Причина передачи оператору: {{ $selectedTicket->escalationReasonLabel() }}</li>
                        @if ($selectedTicket->status === \App\Enums\TicketStatus::Closed)
                            <li>Закрыто: {{ $selectedTicket->closed_at?->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</li>
                            <li>Способ закрытия: {{ $selectedTicket->close_reason?->label() ?? 'Не указан' }}</li>
                        @endif
                    </ul>
                    @if ($selectedTicket->status !== \App\Enums\TicketStatus::Closed)
                        <div class="mt-6 space-y-2 border-t border-slate-200 pt-5 dark:border-slate-700">
                            @if ($selectedTicket->status === \App\Enums\TicketStatus::Open)
                                <button wire:click="resolveTicket" type="button" class="w-full rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300 dark:hover:bg-emerald-900" wire:loading.attr="disabled">Отметить решённым</button>
                            @endif
                            <button wire:click="closeTicket" type="button" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-xs text-rose-600 hover:bg-rose-50 dark:border-slate-700 dark:text-rose-400 dark:hover:bg-rose-950" wire:loading.attr="disabled">Закрыть обращение</button>
                        </div>
                    @endif
                @else
                    <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">Статус и действия появятся после выбора обращения.</p>
                @endif
            </aside>
        </div>
    @elseif ($section === 'bot')
        <div wire:key="bot-workspace" class="grid rounded-2xl border border-slate-200 bg-white shadow-xs lg:h-[calc(100dvh-14rem)] lg:min-h-[32rem] lg:grid-cols-[17rem_minmax(0,1fr)_18rem] lg:overflow-hidden dark:border-slate-700 dark:bg-slate-900">
            <aside aria-label="Список ответов бота" class="flex min-h-0 flex-col border-b border-slate-200 lg:border-r lg:border-b-0 dark:border-slate-700">
                <header class="border-b border-slate-200 p-4 dark:border-slate-700"><h2 class="text-sm font-semibold">Ответы бота</h2><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Самостоятельные ответы и отказы</p></header>
                <div class="max-h-72 flex-1 overflow-y-auto p-2 lg:max-h-none">
                    @forelse ($botReplies as $botReply)
                        <button wire:click="selectBotReply({{ $botReply->id }})" wire:key="bot-reply-{{ $botReply->id }}" type="button" aria-pressed="{{ $selectedBotReply?->id === $botReply->id ? 'true' : 'false' }}" @class(['mb-1 w-full rounded-xl border p-3 text-left', 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950' => $selectedBotReply?->id === $botReply->id, 'border-transparent hover:bg-slate-50 dark:hover:bg-slate-800' => $selectedBotReply?->id !== $botReply->id])>
                            <p class="line-clamp-2 text-xs font-medium wrap-anywhere">{{ $botReply->sourceMessage->body }}</p>
                            <p class="mt-1.5 truncate text-xs text-slate-500 dark:text-slate-400">{{ $botReply->body }}</p>
                            <div class="mt-2 flex flex-wrap justify-between gap-1 text-[10px] text-slate-500 dark:text-slate-400"><time>{{ $botReply->created_at->copy()->timezone('Europe/Moscow')->format('d.m H:i') }}</time><span>{{ $botReply->delivery_status?->label() ?? 'Не указана' }}</span></div>
                        </button>
                    @empty
                        <p class="px-3 py-8 text-center text-sm text-slate-500 dark:text-slate-400">Самостоятельных ответов бота пока нет.</p>
                    @endforelse
                </div>
                <x-cursor-navigation :paginator="$botReplies" name="botRepliesCursor" label="Страницы ответов бота" previous-label="Новые ответы" next-label="Старые ответы" latest-label="Последние ответы" class="border-t border-slate-200 p-3 dark:border-slate-700" />
            </aside>
            <section aria-label="Диалог с ботом" class="flex min-h-96 min-w-0 flex-col bg-slate-50 lg:min-h-0 dark:bg-slate-950/50">
                @if ($selectedBotReply !== null)
                    <header class="border-b border-slate-200 bg-white px-5 py-4 dark:border-slate-700 dark:bg-slate-900"><h2 class="text-sm font-semibold">Диалог с ботом</h2><p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Участник: {{ $selectedBotReply->participant->telegram_user_id }}</p></header>
                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-5 sm:px-6">
                        <x-message-bubble :message="$selectedBotReply->sourceMessage" wire:key="bot-question-{{ $selectedBotReply->source_message_id }}" />
                        <x-message-bubble :message="$selectedBotReply" wire:key="bot-answer-{{ $selectedBotReply->id }}">
                            @if ($selectedBotReply->delivery_status === \App\Enums\DeliveryStatus::Failed)
                                <p class="text-xs text-rose-600 dark:text-rose-400">Ошибка: {{ $selectedBotReply->last_delivery_error }}</p>
                            @endif
                        </x-message-bubble>
                    </div>
                    <footer class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">Только просмотр · самостоятельный ответ без обращения</footer>
                @else
                    <div class="flex flex-1 items-center justify-center p-8 text-center text-sm text-slate-500 dark:text-slate-400">Выберите ответ бота, чтобы увидеть вопрос и ответ участнику.</div>
                @endif
            </section>
            <aside aria-label="Основание ответа бота" class="min-h-0 overflow-y-auto border-t border-slate-200 p-5 lg:border-t-0 lg:border-l dark:border-slate-700">
                <h2 class="text-sm font-semibold">Основание ответа</h2>
                @if ($selectedBotReply !== null)
                    @php($decision = $selectedBotReply->sourceMessage->decision)
                    <dl class="mt-5 space-y-4 text-xs">
                        <div><dt class="text-slate-500 dark:text-slate-400">Решение</dt><dd class="mt-1 font-medium">{{ $decision->type === \App\Enums\SupportDecisionType::Answer ? 'Ответ по правилам' : 'Отказ' }} <span class="font-normal text-slate-500 dark:text-slate-400">({{ $decision->type->value }})</span></dd></div>
                        <div><dt class="text-slate-500 dark:text-slate-400">Причина</dt><dd class="mt-1 wrap-anywhere">{{ $decision->reason }}</dd></div>
                        <div><dt class="text-slate-500 dark:text-slate-400">Доставка</dt><dd class="mt-1">{{ $selectedBotReply->delivery_status?->label() ?? 'Не указана' }}</dd></div>
                        @if ($selectedBotReply->delivered_at !== null)
                            <div><dt class="text-slate-500 dark:text-slate-400">Доставлено</dt><dd class="mt-1">{{ $selectedBotReply->delivered_at->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</dd></div>
                        @endif
                    </dl>
                    <section class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-700">
                        <h3 class="text-xs font-semibold">Пункты правил</h3>
                        @forelse ($decision->structured_output['evidence'] ?? [] as $index => $evidence)
                            <blockquote wire:key="evidence-{{ $decision->id }}-{{ $index }}" class="mt-3 border-l-2 border-emerald-500 pl-3 text-xs leading-relaxed text-slate-600 dark:text-slate-300"><p class="mb-1 font-medium text-emerald-700 dark:text-emerald-400">Пункт {{ $evidence['rule_id'] ?? 'не указан' }}</p>{{ $evidence['quote'] ?? '' }}</blockquote>
                        @empty
                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Ссылки на пункты правил отсутствуют.</p>
                        @endforelse
                    </section>
                @else
                    <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">Здесь появятся решение и ссылки на правила.</p>
                @endif
            </aside>
        </div>
    @else
        <section wire:key="statistics-workspace" aria-label="Подробная статистика" class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
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
