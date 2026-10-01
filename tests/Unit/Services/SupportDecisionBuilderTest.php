<?php

use App\Data\LlmAnalysisPart;
use App\Data\ValidatedLlmAnalysis;
use App\Enums\LlmAnalysisKind;
use App\Enums\SupportDecisionType;
use App\Services\SupportDecisionBuilder;

test('builds deterministic final decisions from analysis parts', function (array $parts, SupportDecisionType $type, string $reason) {
    $decision = (new SupportDecisionBuilder)->build(new ValidatedLlmAnalysis($parts));

    expect($decision->type)->toBe($type)
        ->and($decision->reason)->toBe($reason);
})->with([
    'grounded answer' => [[new LlmAnalysisPart(LlmAnalysisKind::RuleAnswer, 'Кефир не участвует.', ['4.1'])], SupportDecisionType::Answer, 'rule_answer'],
    'participant escalation' => [[new LlmAnalysisPart(LlmAnalysisKind::ParticipantSpecific, null, [])], SupportDecisionType::Escalate, 'participant_specific'],
    'unknown escalation' => [[new LlmAnalysisPart(LlmAnalysisKind::NotInRules, null, [])], SupportDecisionType::Escalate, 'not_in_rules'],
    'prompt injection refusal' => [[new LlmAnalysisPart(LlmAnalysisKind::PromptInjection, null, [])], SupportDecisionType::Refuse, 'prompt_injection'],
]);

test('builds a mixed decision for delivery status and prize replacement', function () {
    $analysis = new ValidatedLlmAnalysis([
        new LlmAnalysisPart(LlmAnalysisKind::ParticipantSpecific, null, []),
        new LlmAnalysisPart(
            LlmAnalysisKind::RuleAnswer,
            'Выплата денежного эквивалента призов и замена призов другими не производятся.',
            ['7.4'],
        ),
    ]);

    $decision = (new SupportDecisionBuilder)->build($analysis);

    expect($decision->type)->toBe(SupportDecisionType::Mixed)
        ->and($decision->reason)->toBe('mixed_request')
        ->and($decision->answer)->toBe('Выплата денежного эквивалента призов и замена призов другими не производятся.')
        ->and($decision->sourceRules)->toBe(['7.4']);
});
