<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['participant_id', 'status', 'escalation_reason', 'first_operator_replied_at', 'waiting_since', 'closed_at', 'close_reason'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TelegramParticipant::class, 'participant_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function hasUnfinishedOperatorReply(): bool
    {
        return $this->messages()
            ->where('direction', MessageDirection::Outbound->value)
            ->where('author', MessageAuthor::Operator->value)
            ->whereIn('delivery_status', [DeliveryStatus::Pending->value, DeliveryStatus::Failed->value])
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'close_reason' => TicketCloseReason::class,
            'first_operator_replied_at' => 'datetime',
            'waiting_since' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
