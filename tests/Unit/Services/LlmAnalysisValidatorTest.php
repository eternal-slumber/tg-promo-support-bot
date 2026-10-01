<?php

use App\Enums\LlmAnalysisKind;
use App\Exceptions\InvalidLlmDecisionException;
use App\Services\LlmAnalysisValidator;

test('validates structured analysis parts without participant text', function () {
    $analysis = (new LlmAnalysisValidator)->validate([
        'parts' => [
            ['kind' => 'participant_specific', 'answer' => null, 'source_rules' => []],
            ['kind' => 'rule_answer', 'answer' => 'Денежная замена не предусмотрена.', 'source_rules' => ['7.4']],
        ],
    ]);

    expect($analysis->parts)->toHaveCount(2)
        ->and($analysis->parts[0]->kind)->toBe(LlmAnalysisKind::ParticipantSpecific)
        ->and($analysis->toStructuredOutput())->not->toContain('Я выиграл йогуртницу');
});

test('rejects invalid or contradictory analysis parts', function (mixed $output) {
    expect(fn () => (new LlmAnalysisValidator)->validate($output))
        ->toThrow(InvalidLlmDecisionException::class);
})->with([
    'missing parts' => [[]],
    'unknown kind' => [['parts' => [['kind' => 'unknown', 'answer' => null, 'source_rules' => []]]]],
    'grounded part without answer' => [['parts' => [['kind' => 'rule_answer', 'answer' => null, 'source_rules' => ['7.4']]]]],
    'participant part with answer' => [['parts' => [['kind' => 'participant_specific', 'answer' => 'Статус', 'source_rules' => []]]]],
]);
