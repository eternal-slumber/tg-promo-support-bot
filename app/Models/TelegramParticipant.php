<?php

namespace App\Models;

use Database\Factories\TelegramParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['telegram_user_id', 'chat_id'])]
class TelegramParticipant extends Model
{
    /** @use HasFactory<TelegramParticipantFactory> */
    use HasFactory;

    public function updates(): HasMany
    {
        return $this->hasMany(TelegramUpdate::class, 'participant_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'participant_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'participant_id');
    }
}
