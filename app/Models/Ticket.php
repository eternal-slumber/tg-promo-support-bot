<?php

namespace App\Models;

use App\Enums\TicketCloseReason;
use App\Enums\TicketEscalationReason;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['participant_id', 'status', 'escalation_reason', 'first_operator_replied_at', 'resolved_since', 'closed_at', 'close_reason'])]
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

    public function escalationReasonLabel(): string
    {
        return TicketEscalationReason::tryFrom($this->escalation_reason ?? '')?->label() ?? 'Не указана';
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
            'resolved_since' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
