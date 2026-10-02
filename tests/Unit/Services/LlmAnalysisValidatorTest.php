<?php

use App\Enums\LlmAnalysisKind;
use App\Exceptions\InvalidLlmDecisionException;
use App\Services\LlmAnalysisValidator;
use App\Services\SupportDecisionBuilder;

test('validates structured analysis parts without participant text', function () {
    $analysis = (new LlmAnalysisValidator)->validate([
        'parts' => [
            ['kind' => 'participant_specific', 'answer' => null, 'evidence' => []],
            ['kind' => 'rule_answer', 'answer' => 'Денежная замена не предусмотрена.', 'evidence' => [['rule_id' => '7.4', 'quote' => 'Денежная замена не предусмотрена.']]],
        ],
    ], '7.4. Денежная замена не предусмотрена.');

    expect($analysis->parts)->toHaveCount(2)
        ->and($analysis->parts[0]->kind)->toBe(LlmAnalysisKind::ParticipantSpecific)
        ->and($analysis->toStructuredOutput())->not->toContain('Я выиграл йогуртницу');
});

test('rejects invalid or contradictory analysis parts', function (mixed $output) {
    expect(fn () => (new LlmAnalysisValidator)->validate($output, '7.4. Денежная замена не предусмотрена.'))
        ->toThrow(InvalidLlmDecisionException::class);
})->with([
    'missing parts' => [[]],
    'unknown kind' => [['parts' => [['kind' => 'unknown', 'answer' => null, 'evidence' => []]]]],
    'grounded part without answer' => [['parts' => [['kind' => 'rule_answer', 'answer' => null, 'evidence' => [['rule_id' => '7.4', 'quote' => 'Денежная замена не предусмотрена.']]]]]],
    'participant part with answer' => [['parts' => [['kind' => 'participant_specific', 'answer' => 'Статус', 'evidence' => []]]]],
    'grounded part without evidence' => [['parts' => [['kind' => 'rule_answer', 'answer' => 'Вы выиграли автомобиль', 'evidence' => []]]]],
    'legacy references are not evidence' => [['parts' => [['kind' => 'rule_answer', 'answer' => 'Вы выиграли автомобиль', 'source_rules' => ['999.42']]]]],
    'unknown rule id' => [['parts' => [['kind' => 'rule_answer', 'answer' => 'Вы выиграли автомобиль', 'evidence' => [['rule_id' => '999.42', 'quote' => 'Денежная замена не предусмотрена.']]]]]],
    'fabricated quote' => [['parts' => [['kind' => 'rule_answer', 'answer' => 'Вы выиграли автомобиль', 'evidence' => [['rule_id' => '7.4', 'quote' => 'Вы выиграли автомобиль']]]]]],
    'empty quote' => [['parts' => [['kind' => 'rule_answer', 'answer' => 'Ответ', 'evidence' => [['rule_id' => '7.4', 'quote' => ' ']]]]]],
    'participant with evidence' => [['parts' => [['kind' => 'participant_specific', 'answer' => null, 'evidence' => [['rule_id' => '7.4', 'quote' => 'Денежная замена не предусмотрена.']]]]]],
]);

test('limits factual response to verified evidence instead of a fabricated answer', function () {
    $analysis = (new LlmAnalysisValidator)->validate(['parts' => [[
        'kind' => 'rule_answer',
        'answer' => 'Вы выиграли автомобиль',
        'evidence' => [['rule_id' => '7.4', 'quote' => 'предусмотрена.']],
    ]]], '7.4. Денежная замена не предусмотрена.');

    $decision = (new SupportDecisionBuilder)->build($analysis);

    expect($decision->answer)->toBe('Денежная замена не предусмотрена.')
        ->and($analysis->toStructuredOutput()['parts'][0]['answer'])->not->toContain('автомобиль');
});

test('checks quote membership in the specified rule and retains multiline clauses', function () {
    $rules = "4.1. Участвуют йогурты:\n- питьевые;\n- густые.\n\n4.2. Кефир не участвует.\n\n## 5. Следующий раздел\n";
    $validator = new LlmAnalysisValidator;
    $part = ['kind' => 'rule_answer', 'answer' => 'Ответ', 'evidence' => [['rule_id' => '4.1', 'quote' => "Участвуют йогурты:\n- питьевые;\n- густые."]]];

    expect($validator->validate(['parts' => [$part]], $rules)->parts[0]->sourceRules)->toBe(['4.1']);
    $part['evidence'] = [['rule_id' => '4.1', 'quote' => 'Кефир не участвует.']];

    expect(fn () => $validator->validate(['parts' => [$part]], $rules))->toThrow(InvalidLlmDecisionException::class);
});
