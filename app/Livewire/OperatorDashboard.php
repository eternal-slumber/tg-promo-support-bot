<?php

namespace App\Livewire;

use App\Data\TelegramOutboundMessage;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\SupportDecisionType;
use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LlmDecisionValidator;
use App\Services\OperatorReplyService;
use App\Services\TicketLifecycleService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class OperatorDashboard extends Component
{
    use WithPagination;

    public ?int $selectedTicketId = null;

    public string $replyBody = '';

    public string $filter = 'active';

    public string $section = 'tickets';

    public ?int $selectedBotReplyId = null;

    public function selectSection(string $section): void
    {
        $this->operator();

        if (in_array($section, ['tickets', 'bot', 'statistics'], true)) {
            $this->section = $section;
        }
    }

    public function selectBotReply(int $messageId): void
    {
        $this->operator();

        if (! $this->visibleBotReplies()->whereKey($messageId)->exists()) {
            $this->addError('botReply', 'Ответ бота недоступен.');

            return;
        }

        $this->selectedBotReplyId = $messageId;
        $this->resetValidation('botReply');
    }

    public function selectFilter(string $filter): void
    {
        if (! in_array($filter, ['active', 'closed', 'all'], true)) {
            return;
        }

        $this->filter = $filter;
        $this->reset('selectedTicketId', 'replyBody');
        $this->resetValidation();
        $this->resetPage('ticketsCursor');
        $this->resetPage('contextCursor');
    }

    public function selectTicket(int $ticketId): void
    {
        $ticket = $this->visibleTickets()
            ->whereKey($ticketId)
            ->first();

        if ($ticket === null) {
            $this->addError('ticket', 'Обращение недоступно в выбранном фильтре.');

            return;
        }

        if ($this->selectedTicketId !== $ticket->id) {
            $this->reset('replyBody');
            $this->resetValidation();
            $this->resetPage('contextCursor');
        }

        $this->selectedTicketId = $ticket->id;
    }

    public function sendReply(OperatorReplyService $operatorReplies): void
    {
        $this->validate([
            'replyBody' => ['required', 'string', 'max:'.TelegramOutboundMessage::MaxTextLength],
        ], ['replyBody.max' => 'Ответ слишком длинный. Сократите его с учётом номера обращения и цитаты.']);

        $ticket = $this->activeTicket();

        if ($ticket === null) {
            $this->addError('replyBody', 'Обращение уже закрыто.');

            return;
        }

        try {
            $operatorReplies->create($this->operator(), $ticket, $this->replyBody);
        } catch (DomainException) {
            $this->addError('replyBody', 'Новый ответ недоступен: обращение уже закрыто.');

            return;
        }

        $this->reset('replyBody');
    }

    public function resolveTicket(TicketLifecycleService $ticketLifecycle): void
    {
        $this->operator();
        $ticket = $this->activeTicket();

        if ($ticket === null) {
            $this->addError('ticket', 'Обращение уже закрыто.');

            return;
        }

        try {
            $ticketLifecycle->resolve($ticket);
        } catch (DomainException) {
            $this->addError('ticket', 'Решение недоступно: обращение уже решено или закрыто.');

            return;
        }

        $this->resetValidation();
    }

    public function refreshOperatorReply(int $messageId): void
    {
        $this->operator();

        $deliveryStatus = Message::query()
            ->whereKey($messageId)
            ->where('ticket_id', $this->selectedTicketId)
            ->where('direction', MessageDirection::Outbound)
            ->where('author', MessageAuthor::Operator)
            ->value('delivery_status');

        if ($deliveryStatus === null || $deliveryStatus === DeliveryStatus::Pending) {
            $this->skipRender();
        }
    }

    public function retryDelivery(int $messageId, OperatorReplyService $operatorReplies): void
    {
        $this->operator();
        $ticket = Ticket::query()->find($this->selectedTicketId);

        if ($ticket === null) {
            return;
        }

        try {
            $operatorReplies->retry($ticket, $messageId);
        } catch (DomainException) {
            $this->addError('ticket', 'Повтор недоступен: ответ уже отправляется, доставлен или отменён.');

            return;
        }

        $this->resetValidation();
    }

    public function cancelDelivery(int $messageId, OperatorReplyService $operatorReplies): void
    {
        $this->operator();
        $ticket = Ticket::query()->find($this->selectedTicketId);

        if ($ticket === null) {
            $this->addError('ticket', 'Обращение недоступно.');

            return;
        }

        try {
            $operatorReplies->cancel($ticket, $messageId);
        } catch (DomainException) {
            $this->addError('ticket', 'Отмена недоступна: ответ уже отправляется, доставлен или отменён.');

            return;
        }

        $this->resetValidation();
    }

    public function closeTicket(TicketLifecycleService $ticketLifecycle): void
    {
        $this->operator();
        $ticket = $this->activeTicket();

        if ($ticket === null) {
            $this->addError('ticket', 'Обращение уже закрыто.');

            return;
        }

        try {
            $ticketLifecycle->closeManually($ticket);
        } catch (DomainException) {
            $this->addError('ticket', 'Закрытие недоступно: обращение уже закрыто.');

            return;
        }

        $this->reset('selectedTicketId', 'replyBody');
        $this->resetValidation();
    }

    public function render(): View
    {
        $tickets = $this->visibleTickets()
            ->latest('created_at')
            ->latest('id')
            ->cursorPaginate(20, ['id', 'status', 'escalation_reason', 'created_at'], 'ticketsCursor');

        $selectedTicket = $this->selectedTicketId === null ? null : Ticket::query()
            ->with('participant:id,telegram_user_id')
            ->whereKey($this->selectedTicketId)
            ->first();

        $messages = $selectedTicket?->messages()
            ->latest('created_at')
            ->latest('id')
            ->get(['id', 'ticket_id', 'ticket_event', 'created_at', 'author', 'body', 'direction', 'delivery_status', 'last_delivery_error']);

        $contextMessages = ($selectedTicket?->context_message_ids ?? []) === [] ? collect() : $selectedTicket->contextMessages()
            ->oldest('id')
            ->cursorPaginate(50, ['id', 'author', 'body', 'created_at'], 'contextCursor');

        $hasUnfinishedReply = $selectedTicket?->messages()
            ->where('direction', MessageDirection::Outbound)
            ->where('author', MessageAuthor::Operator)
            ->whereIn('delivery_status', [DeliveryStatus::Pending, DeliveryStatus::Failed])
            ->exists() ?? false;

        $botReplies = $this->section === 'bot' ? $this->visibleBotReplies()
            ->with('sourceMessage:id,body')
            ->latest('created_at')
            ->latest('id')
            ->cursorPaginate(20, ['id', 'source_message_id', 'body', 'created_at', 'delivery_status'], 'botRepliesCursor') : null;

        $selectedBotReply = $this->section !== 'bot' || $this->selectedBotReplyId === null ? null : $this->visibleBotReplies()
            ->with([
                'participant:id,telegram_user_id',
                'sourceMessage:id,body,created_at,author,direction',
                'sourceMessage.decision:id,message_id,type,reason,structured_output,knowledge_source_hash',
            ])
            ->whereKey($this->selectedBotReplyId)
            ->first();

        return view('livewire.operator-dashboard', [
            'tickets' => $tickets,
            'selectedTicket' => $selectedTicket,
            'messages' => $messages,
            'contextMessages' => $contextMessages,
            'hasUnfinishedReply' => $hasUnfinishedReply,
            'botReplies' => $botReplies,
            'selectedBotReply' => $selectedBotReply,
            'statistics' => $this->statistics(),
        ]);
    }

    /**
     * Unticketed bot messages are grounded answers or the validator's fixed refusal.
     *
     * @return array{
     *     bot_resolved: int,
     *     bot_prepared: int,
     *     bot_pending: int,
     *     bot_failed: int,
     *     bot_cancelled: int,
     *     escalated: int,
     *     average_operator_response_seconds: ?float,
     *     operator_cancelled: int
     * }
     */
    private function statistics(): array
    {
        $botDeliveries = Message::query()
            ->where('direction', MessageDirection::Outbound)
            ->where('author', MessageAuthor::Bot)
            ->whereNull('ticket_id')
            ->where('body', '!=', LlmDecisionValidator::RefusalAnswer)
            ->toBase()
            ->selectRaw('delivery_status, COUNT(*) AS aggregate, COUNT(delivered_at) AS delivered')
            ->groupBy('delivery_status')
            ->get()
            ->keyBy('delivery_status');
        $average = Ticket::query()->whereNotNull('first_operator_replied_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (first_operator_replied_at - created_at))) AS seconds')
            ->value('seconds');

        return [
            'bot_resolved' => (int) ($botDeliveries->get(DeliveryStatus::Sent->value)?->delivered ?? 0),
            'bot_prepared' => (int) $botDeliveries->sum('aggregate'),
            'bot_pending' => (int) ($botDeliveries->get(DeliveryStatus::Pending->value)?->aggregate ?? 0),
            'bot_failed' => (int) ($botDeliveries->get(DeliveryStatus::Failed->value)?->aggregate ?? 0),
            'bot_cancelled' => (int) ($botDeliveries->get(DeliveryStatus::Cancelled->value)?->aggregate ?? 0),
            'escalated' => Ticket::query()->count(),
            'average_operator_response_seconds' => $average === null ? null : (float) $average,
            'operator_cancelled' => Message::query()
                ->where('direction', MessageDirection::Outbound)
                ->where('author', MessageAuthor::Operator)
                ->where('delivery_status', DeliveryStatus::Cancelled)
                ->count(),
        ];
    }

    private function visibleTickets(): Builder
    {
        return match ($this->filter) {
            'closed' => Ticket::query()->where('status', TicketStatus::Closed->value),
            'all' => Ticket::query(),
            default => Ticket::query()->whereIn('status', [TicketStatus::Open->value, TicketStatus::Resolved->value]),
        };
    }

    private function visibleBotReplies(): Builder
    {
        return Message::query()
            ->whereNull('ticket_id')
            ->where('direction', MessageDirection::Outbound)
            ->where('author', MessageAuthor::Bot)
            ->whereHas('sourceMessage', fn (Builder $query): Builder => $query
                ->where('direction', MessageDirection::Inbound)
                ->where('author', MessageAuthor::Participant)
                ->whereHas('decision', fn (Builder $decisions): Builder => $decisions
                    ->whereIn('type', [SupportDecisionType::Answer, SupportDecisionType::Refuse])));
    }

    private function activeTicket(): ?Ticket
    {
        return Ticket::query()
            ->whereKey($this->selectedTicketId)
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::Resolved->value])
            ->first();
    }

    private function operator(): User
    {
        return auth()->user() instanceof User ? auth()->user() : abort(403);
    }
}
