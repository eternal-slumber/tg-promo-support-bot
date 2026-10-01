<?php

namespace App\Models;

use App\Enums\SupportDecisionType;
use Database\Factories\SupportDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['message_id', 'type', 'reason', 'answer_text', 'knowledge_source_hash', 'structured_output'])]
class SupportDecision extends Model
{
    /** @use HasFactory<SupportDecisionFactory> */
    use HasFactory;

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SupportDecisionType::class,
            'structured_output' => 'array',
        ];
    }
}
