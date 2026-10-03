<?php

namespace App\Livewire;

use App\Data\TelegramOutboundMessage;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Jobs\DeliverTelegramMessage;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
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

    public function selectFilter(string $filter): void
    {
        if (! in_array($filter, ['active', 'closed', 'all'], true)) {
            return;
        }

        $this->filter = $filter;
        $this->reset('selectedTicketId', 'replyBody');
        $this->resetValidation();
        $this->resetPage('ticketsCursor');
        $this->resetPage('messagesCursor');
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
            $this->resetPage('messagesCursor');
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
            $this->addError('replyBody', 'Обращение уже закрыто или ожидает ответа участника.');

            return;
        }

        try {
            $operatorReplies->create($this->operator(), $ticket, $this->replyBody);
        } catch (DomainException) {
            $this->addError('replyBody', 'Новый ответ недоступен: обращение закрыто, ожидает участника или уже содержит недоставленный ответ.');

            return;
        }

        $this->reset('replyBody');
        $this->resetPage('messagesCursor');
    }

    public function retryDelivery(int $messageId): void
    {
        $this->operator();
        $ticket = $this->activeTicket();

        if ($ticket === null) {
            return;
        }

        $message = Message::query()
            ->whereKey($messageId)
            ->where('ticket_id', $ticket->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->where('author', MessageAuthor::Operator->value)
            ->where('delivery_status', DeliveryStatus::Failed->value)
            ->firstOrFail();

        DeliverTelegramMessage::dispatch($message->id);
    }

    public function cancelDelivery(int $messageId, OperatorReplyService $operatorReplies): void
    {
        $this->operator();
        $ticket = $this->activeTicket();

        if ($ticket === null) {
            $this->addError('ticket', 'Обращение уже закрыто.');

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
            $this->addError('ticket', 'Закрытие недоступно: обращение уже закрыто или содержит недоставленный ответ оператора.');

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
            ->cursorPaginate(50, ['id', 'ticket_id', 'created_at', 'author', 'body', 'direction', 'delivery_status', 'last_delivery_error'], 'messagesCursor');

        return view('livewire.operator-dashboard', [
            'tickets' => $tickets,
            'selectedTicket' => $selectedTicket,
            'messages' => $messages,
            'statistics' => $this->statistics(),
        ]);
    }

    /**
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
            ->toBase()
            ->selectRaw('delivery_status, COUNT(*) AS aggregate')
            ->groupBy('delivery_status')
            ->pluck('aggregate', 'delivery_status');
        $average = Ticket::query()->whereNotNull('first_operator_replied_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (first_operator_replied_at - created_at))) AS seconds')
            ->value('seconds');

        return [
            'bot_resolved' => (int) $botDeliveries->get(DeliveryStatus::Sent->value, 0),
            'bot_prepared' => (int) $botDeliveries->sum(),
            'bot_pending' => (int) $botDeliveries->get(DeliveryStatus::Pending->value, 0),
            'bot_failed' => (int) $botDeliveries->get(DeliveryStatus::Failed->value, 0),
            'bot_cancelled' => (int) $botDeliveries->get(DeliveryStatus::Cancelled->value, 0),
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
            default => Ticket::query()->whereIn('status', [TicketStatus::Open->value, TicketStatus::WaitingForUser->value]),
        };
    }

    private function activeTicket(?int $ticketId = null): ?Ticket
    {
        return Ticket::query()
            ->whereKey($ticketId ?? $this->selectedTicketId)
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::WaitingForUser->value])
            ->first();
    }

    private function operator(): User
    {
        return auth()->user() instanceof User ? auth()->user() : abort(403);
    }
}
