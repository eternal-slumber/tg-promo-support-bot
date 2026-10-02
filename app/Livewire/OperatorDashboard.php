<?php

namespace App\Livewire;

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

class OperatorDashboard extends Component
{
    public ?int $selectedTicketId = null;

    public string $replyBody = '';

    public string $filter = 'active';

    public function selectFilter(string $filter): void
    {
        if (! in_array($filter, ['active', 'closed', 'all'], true)) {
            return;
        }

        $this->filter = $filter;
        $this->selectedTicketId = null;
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

        $this->selectedTicketId = $ticket->id;
    }

    public function sendReply(OperatorReplyService $operatorReplies): void
    {
        $this->validate([
            'replyBody' => ['required', 'string', 'max:4000'],
        ]);

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
    }

    public function retryDelivery(int $messageId): void
    {
        $ticket = $this->activeTicket();

        if ($ticket === null) {
            return;
        }

        $message = Message::query()
            ->whereKey($messageId)
            ->where('ticket_id', $ticket->id)
            ->where('operator_id', $this->operator()->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->where('author', MessageAuthor::Operator->value)
            ->where('delivery_status', DeliveryStatus::Failed->value)
            ->firstOrFail();

        DeliverTelegramMessage::dispatch($message->id);
    }

    public function closeTicket(TicketLifecycleService $ticketLifecycle): void
    {
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

        $this->selectedTicketId = null;
    }

    public function render(): View
    {
        $tickets = $this->visibleTickets()
            ->with('participant')
            ->latest('created_at')
            ->latest('id')
            ->get();

        $selectedTicket = $this->selectedTicketId === null ? null : Ticket::query()
            ->with([
                'participant',
                'messages' => fn ($query) => $query->with('operator')->oldest('created_at')->oldest('id'),
            ])
            ->whereKey($this->selectedTicketId)
            ->first();

        return view('livewire.operator-dashboard', [
            'tickets' => $tickets,
            'selectedTicket' => $selectedTicket,
        ]);
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
