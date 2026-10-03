<?php

namespace App\Enums;

enum TicketEscalationReason: string
{
    case LlmFailure = 'llm_failure';
    case ParticipantSpecific = 'participant_specific';
    case NotInRules = 'not_in_rules';
    case MixedRequest = 'mixed_request';

    public function label(): string
    {
        return match ($this) {
            self::LlmFailure => 'Ошибка ИИ',
            self::ParticipantSpecific => 'Требуется проверка данных участника',
            self::NotInRules => 'Нет ответа в правилах',
            self::MixedRequest => 'Часть вопроса требует оператора',
        };
    }
}
