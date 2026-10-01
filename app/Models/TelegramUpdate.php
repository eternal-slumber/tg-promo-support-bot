<?php

namespace App\Models;

use Database\Factories\TelegramUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['update_id', 'participant_id', 'kind', 'received_at'])]
class TelegramUpdate extends Model
{
    /** @use HasFactory<TelegramUpdateFactory> */
    use HasFactory;

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TelegramParticipant::class, 'participant_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'telegram_update_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }
}
