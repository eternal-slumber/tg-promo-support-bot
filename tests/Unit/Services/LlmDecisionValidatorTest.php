<?php

use App\Enums\SupportDecisionType;
use App\Exceptions\InvalidLlmDecisionException;
use App\Services\LlmDecisionValidator;

test('accepts model decisions with the matching reason and evidence', function (string $type, string $reason, ?string $answer, array $evidence) {
    $output = ['decision' => $type, 'reason' => $reason, 'answer' => $answer, 'evidence' => $evidence];

    $decision = (new LlmDecisionValidator)->validate($output, '7.4. Денежная замена не предусмотрена.');

    expect($decision->type)->toBe(SupportDecisionType::from($type));
    expect($decision->toStructuredOutput())->toBe($output);
})->with([
    'grounded answer' => ['answer', 'rule_answer', 'Деньгами заменить приз нельзя.', [['rule_id' => '7.4', 'quote' => 'Денежная замена не предусмотрена.']]],
    'mixed request' => ['mixed', 'mixed_request', 'Деньгами заменить приз нельзя.', [['rule_id' => '7.4', 'quote' => 'Денежная замена не предусмотрена.']]],
    'participant escalation' => ['escalate', 'participant_specific', null, []],
    'unknown escalation' => ['escalate', 'not_in_rules', null, []],
    'prompt injection refusal' => ['refuse', 'prompt_injection', 'Я не могу выполнить этот запрос.', []],
]);

test('rejects missing mistyped extra and contradictory decision fields', function (array $changes) {
    $output = array_replace(['decision' => 'answer', 'reason' => 'rule_answer', 'answer' => 'Ответ.', 'evidence' => [['rule_id' => '7.4', 'quote' => 'Денежная замена не предусмотрена.']]], $changes);

    expect(fn () => (new LlmDecisionValidator)->validate($output, '7.4. Денежная замена не предусмотрена.'))->toThrow(InvalidLlmDecisionException::class);
})->with([
    'unknown decision' => [['decision' => 'unknown']],
    'mistyped decision' => [['decision' => true]],
    'unknown reason' => [['reason' => 'unknown']],
    'mistyped reason' => [['reason' => []]],
    'reason for another decision' => [['reason' => 'mixed_request']],
    'application failure reason' => [['decision' => 'escalate', 'reason' => 'llm_failure', 'answer' => null, 'evidence' => []]],
    'missing answer' => [['answer' => null]],
    'empty answer' => [['answer' => '  ']],
    'mistyped answer' => [['answer' => 1]],
    'no evidence' => [['evidence' => []]],
    'mistyped evidence' => [['evidence' => '7.4']],
    'non-list evidence' => [['evidence' => ['rule_id' => '7.4', 'quote' => 'Денежная замена не предусмотрена.']]],
    'unknown rule' => [['evidence' => [['rule_id' => '999.42', 'quote' => 'Денежная замена не предусмотрена.']]]],
    'fabricated quote' => [['evidence' => [['rule_id' => '7.4', 'quote' => 'Вы выиграли автомобиль']]]],
    'empty quote' => [['evidence' => [['rule_id' => '7.4', 'quote' => ' ']]]],
    'mistyped quote' => [['evidence' => [['rule_id' => '7.4', 'quote' => true]]]],
    'mistyped rule id' => [['evidence' => [['rule_id' => 7.4, 'quote' => 'Денежная замена не предусмотрена.']]]],
    'extra evidence field' => [['evidence' => [['rule_id' => '7.4', 'quote' => 'Денежная замена не предусмотрена.', 'other' => true]]]],
    'extra root field' => [['other' => true]],
    'personal request with answer' => [['decision' => 'escalate', 'reason' => 'participant_specific']],
    'personal request with evidence' => [['decision' => 'escalate', 'reason' => 'participant_specific', 'answer' => null]],
    'mixed without evidence' => [['decision' => 'mixed', 'reason' => 'mixed_request', 'evidence' => []]],
    'mixed without answer' => [['decision' => 'mixed', 'reason' => 'mixed_request', 'answer' => null]],
    'refusal containing promotional facts' => [['decision' => 'refuse', 'reason' => 'prompt_injection', 'answer' => 'Вы выиграли автомобиль', 'evidence' => []]],
    'refusal without answer' => [['decision' => 'refuse', 'reason' => 'prompt_injection', 'answer' => null, 'evidence' => []]],
    'refusal with evidence' => [['decision' => 'refuse', 'reason' => 'prompt_injection', 'answer' => 'Я не могу выполнить этот запрос.']],
]);

test('rejects malformed root schemas and every missing required field', function (mixed $output) {
    expect(fn () => (new LlmDecisionValidator)->validate($output, '7.4. Денежная замена не предусмотрена.'))->toThrow(InvalidLlmDecisionException::class);
})->with([
    'scalar' => [true],
    'empty object' => [[]],
    'legacy parts' => [['parts' => [['kind' => 'rule_answer', 'answer' => 'Ответ.', 'evidence' => []]]]],
    'no decision' => [['reason' => 'not_in_rules', 'answer' => null, 'evidence' => []]],
    'no reason' => [['decision' => 'escalate', 'answer' => null, 'evidence' => []]],
    'no answer key' => [['decision' => 'escalate', 'reason' => 'not_in_rules', 'evidence' => []]],
    'no evidence key' => [['decision' => 'escalate', 'reason' => 'not_in_rules', 'answer' => null]],
]);

test('preserves the concrete answer and normalizes only evidence whitespace', function () {
    $decision = (new LlmDecisionValidator)->validate([
        'decision' => 'answer', 'reason' => 'rule_answer', 'answer' => '  Деньгами заменить приз нельзя.  ',
        'evidence' => [['rule_id' => '7.4', 'quote' => "Денежная замена\nне предусмотрена."]],
    ], '7.4. Денежная замена не предусмотрена. Другая информация о призах.');

    expect($decision->answer)->toBe('Деньгами заменить приз нельзя.');
    expect($decision->evidence)->toBe([['rule_id' => '7.4', 'quote' => 'Денежная замена не предусмотрена.']]);
});

test('rejects a real quote when it belongs to a different rule', function () {
    $output = ['decision' => 'answer', 'reason' => 'rule_answer', 'answer' => 'Кефир не участвует.', 'evidence' => [['rule_id' => '4.1', 'quote' => 'Кефир не участвует.']]];

    expect(fn () => (new LlmDecisionValidator)->validate($output, "4.1. Участвуют йогурты.\n4.2. Кефир не участвует."))->toThrow(InvalidLlmDecisionException::class);
});
