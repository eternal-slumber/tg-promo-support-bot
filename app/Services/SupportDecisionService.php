<?php

namespace App\Services;

use App\Data\ValidatedSupportDecision;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\SupportDecisionType;
use App\Enums\TicketStatus;
use App\Jobs\DeliverTelegramMessage;
use App\Models\Message;
use App\Models\SupportDecision;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class SupportDecisionService
{
    public function apply(Message $message, ValidatedSupportDecision $decision, string $rulesHash): ?SupportDecision
    {
        return DB::transaction(function () use ($message, $decision, $rulesHash): ?SupportDecision {
            $lockedMessage = Message::query()->lockForUpdate()->findOrFail($message->id);
            $existingDecision = SupportDecision::query()->where('message_id', $lockedMessage->id)->first();

            if ($existingDecision !== null) {
                return $existingDecision;
            }

            if ($lockedMessage->direction !== MessageDirection::Inbound
                || $lockedMessage->author !== MessageAuthor::Participant
                || $lockedMessage->ticket_id !== null) {
                return null;
            }

            $persistedDecision = $lockedMessage->decision()->create([
                'type' => $decision->type,
                'reason' => $decision->reason,
                'answer_text' => $decision->answer,
                'knowledge_source_hash' => $rulesHash,
                'structured_output' => $decision->toStructuredOutput(),
            ]);

            $participant = TelegramParticipant::query()->lockForUpdate()->findOrFail($lockedMessage->participant_id);
            $ticket = match ($decision->type) {
                SupportDecisionType::Escalate, SupportDecisionType::Mixed => $this->activeTicketOrCreate($participant, $decision->reason),
                SupportDecisionType::Answer, SupportDecisionType::Refuse => null,
            };

            if ($ticket !== null && $lockedMessage->ticket_id === null) {
                $lockedMessage->update(['ticket_id' => $ticket->id]);
            }

            match ($decision->type) {
                SupportDecisionType::Answer, SupportDecisionType::Refuse => $this->createPendingBotMessage($lockedMessage, null, $decision->answer),
                SupportDecisionType::Escalate => $this->createPendingBotMessage($lockedMessage, $ticket, $this->escalationNotice($ticket)),
                SupportDecisionType::Mixed => $this->createMixedMessages($lockedMessage, $ticket, $decision->answer),
            };

            return $persistedDecision;
        });
    }

    public function failSafeEscalate(Message $message, string $rulesHash): ?SupportDecision
    {
        return $this->apply(
            $message,
            new ValidatedSupportDecision(SupportDecisionType::Escalate, 'llm_failure', null, []),
            $rulesHash,
        );
    }

    private function activeTicketOrCreate(TelegramParticipant $participant, string $reason): Ticket
    {
        $ticket = Ticket::query()
            ->where('participant_id', $participant->id)
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::WaitingForUser->value])
            ->oldest('id')
            ->first();

        if ($ticket !== null) {
            return $ticket;
        }

        return $participant->tickets()->create([
            'status' => TicketStatus::Open,
            'escalation_reason' => $reason,
        ]);
    }

    private function createMixedMessages(Message $message, Ticket $ticket, ?string $answer): void
    {
        $this->createPendingBotMessage($message, $ticket, $answer);
        $this->createPendingBotMessage($message, $ticket, $this->escalationNotice($ticket));
    }

    private function createPendingBotMessage(Message $message, ?Ticket $ticket, string $body): void
    {
        $outbound = Message::query()->create([
            'participant_id' => $message->participant_id,
            'ticket_id' => $ticket?->id,
            'direction' => MessageDirection::Outbound,
            'author' => MessageAuthor::Bot,
            'body' => $body,
            'delivery_status' => DeliveryStatus::Pending,
        ]);

        DeliverTelegramMessage::dispatch($outbound->id)->afterCommit();
    }

    private function escalationNotice(Ticket $ticket): string
    {
        return "Ваш вопрос передан оператору. Номер обращения: #{$ticket->id}.";
    }
}
