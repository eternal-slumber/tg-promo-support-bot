<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['participant_id', 'ticket_id', 'telegram_update_id', 'operator_id', 'direction', 'author', 'body', 'delivery_status', 'telegram_message_id', 'delivered_at', 'delivery_attempts', 'last_delivery_error', 'sensitive_data_redacted', 'redaction_types', 'resolves_ticket'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TelegramParticipant::class, 'participant_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function telegramUpdate(): BelongsTo
    {
        return $this->belongsTo(TelegramUpdate::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(SupportDecision::class);
    }

    public function isInboundParticipantMessage(): bool
    {
        return $this->direction === MessageDirection::Inbound && $this->author === MessageAuthor::Participant;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolves_ticket' => 'boolean',
            'direction' => MessageDirection::class,
            'author' => MessageAuthor::class,
            'delivery_status' => DeliveryStatus::class,
            'delivered_at' => 'datetime',
            'delivery_attempts' => 'integer',
            'sensitive_data_redacted' => 'boolean',
            'redaction_types' => 'array',
        ];
    }
}
