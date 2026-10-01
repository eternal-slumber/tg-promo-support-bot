<?php

use App\Services\SensitiveDataSanitizer;

test('redacts the card from evaluation case 22 without relying on luhn', function () {
    $result = (new SensitiveDataSanitizer)->sanitize('Моя карта 2200 1234 5678 9012, проверьте оплату');

    expect($result->text)->toBe('Моя карта [REDACTED_PAYMENT_CARD], проверьте оплату')
        ->not->toContain('2200 1234 5678 9012')
        ->and($result->wasRedacted)->toBeTrue()
        ->and($result->redactionTypes)->toBe(['payment_card']);
});

test('redacts grouped card-like sequences', function (string $card) {
    $result = (new SensitiveDataSanitizer)->sanitize("Карта: {$card}");

    expect($result->text)->toBe('Карта: [REDACTED_PAYMENT_CARD]')
        ->and($result->redactionTypes)->toBe(['payment_card']);
})->with([
    'spaces' => '2200 1234 5678 9012',
    'hyphens' => '2200-1234-5678-9012',
]);

test('redacts a plain card number when it passes luhn', function () {
    $result = (new SensitiveDataSanitizer)->sanitize('Карта 4111111111111111');

    expect($result->text)->toBe('Карта [REDACTED_PAYMENT_CARD]')
        ->and($result->redactionTypes)->toBe(['payment_card']);
});

test('redacts contextual otp values', function (string $input, string $secret) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toContain('[REDACTED_OTP]')->not->toContain($secret)
        ->and($result->redactionTypes)->toBe(['otp']);
})->with([
    'code from sms' => ['код из смс 123456', '123456'],
    'sms code' => ['sms код: 1234', '1234'],
    'otp' => ['otp 839201', '839201'],
]);

test('redacts contextual passwords', function (string $input, string $secret) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toContain('[REDACTED_PASSWORD]')->not->toContain($secret)
        ->and($result->redactionTypes)->toBe(['password']);
})->with([
    'russian password' => ['пароль: qwerty123', 'qwerty123'],
    'english password' => ['password=hunter2', 'hunter2'],
    'owned password' => ['мой пароль secret-word', 'secret-word'],
]);

test('preserves ordinary numeric text and phone numbers', function (string $input) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toBe($input)
        ->and($result->wasRedacted)->toBeFalse()
        ->and($result->redactionTypes)->toBe([]);
})->with([
    'small numbers' => 'Купил на 1000 рублей, начислили 3500 баллов',
    'date' => 'Покупка была 01.10.2026',
    'receipt number' => 'Номер чека 123456789012',
    'phone' => 'Мой телефон +7 999 123-45-67',
    'invalid long identifier' => 'Номер операции 1234567890123',
]);

test('metadata never contains detected secret values', function () {
    $secrets = ['2200 1234 5678 9012', '123456', 'qwerty123'];
    $result = (new SensitiveDataSanitizer)->sanitize(
        'Карта 2200 1234 5678 9012, код из смс 123456, пароль: qwerty123',
    );
    $metadata = json_encode($result->redactionTypes, JSON_THROW_ON_ERROR);

    expect($result->redactionTypes)->toBe(['payment_card', 'otp', 'password'])
        ->and($result->text)->not->toContain(...$secrets)
        ->and($metadata)->not->toContain(...$secrets);
});
