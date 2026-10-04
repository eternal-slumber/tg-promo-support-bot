<?php

namespace App\Models;

use App\Enums\TicketCloseReason;
use App\Enums\TicketEscalationReason;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['participant_id', 'status', 'escalation_reason', 'context_message_ids', 'first_operator_replied_at', 'resolved_since', 'closed_at', 'close_reason'])]
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

    /** @return Builder<Message> */
    public function contextMessages(): Builder
    {
        return Message::query()
            ->where('participant_id', $this->participant_id)
            ->whereIn('id', $this->context_message_ids ?? []);
    }

    public function escalationReasonLabel(): string
    {
        return TicketEscalationReason::tryFrom($this->escalation_reason ?? '')?->label() ?? 'Не указана';
    }

    protected function resolvedSince(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => $value === null ? null : $this->asDateTime($value)->format('Y-m-d H:i:s.u'),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'close_reason' => TicketCloseReason::class,
            'context_message_ids' => 'array',
            'first_operator_replied_at' => 'datetime',
            'resolved_since' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
