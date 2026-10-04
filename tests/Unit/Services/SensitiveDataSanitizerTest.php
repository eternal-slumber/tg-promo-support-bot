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
    'en dash' => '2200–1234–5678–9012',
    'em dash' => '2200—1234—5678—9012',
    'non-breaking hyphen' => '2200‑1234‑5678‑9012',
    'minus sign' => '2200−1234−5678−9012',
    'non-breaking spaces' => "2200\u{00A0}1234\u{00A0}5678\u{00A0}9012",
    'narrow non-breaking spaces' => "2200\u{202F}1234\u{202F}5678\u{202F}9012",
    'thin spaces' => "2200\u{2009}1234\u{2009}5678\u{2009}9012",
    'multiple spaces' => '2200  1234  5678  9012',
]);

test('redacts a plain card number when it passes luhn', function () {
    $result = (new SensitiveDataSanitizer)->sanitize('Карта 4111111111111111');

    expect($result->text)->toBe('Карта [REDACTED_PAYMENT_CARD]')
        ->and($result->redactionTypes)->toBe(['payment_card']);
});

test('redacts explicitly labelled plain card secrets without requiring luhn', function (string $input, string $expected) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toBe($expected);
    expect($result->wasRedacted)->toBeTrue();
    expect($result->redactionTypes)->toBe(['payment_card']);
})->with([
    'plain Russian card' => ['Моя карта 2200123456789012, проверьте оплату', 'Моя карта [REDACTED_PAYMENT_CARD], проверьте оплату'],
    'card number declaration' => ['Номер карты: 2200123456789012. Почему отклонили чек?', 'Номер карты: [REDACTED_PAYMENT_CARD]. Почему отклонили чек?'],
    'transfer destination' => ['Переведите на карту 2200123456789012 100 рублей', 'Переведите на карту [REDACTED_PAYMENT_CARD] 100 рублей'],
    'English card number' => ['Card number: 2200123456789012; check payment', 'Card number: [REDACTED_PAYMENT_CARD]; check payment'],
    'Unicode separator' => ["Карта:\u{00A0}2200123456789012", "Карта:\u{00A0}[REDACTED_PAYMENT_CARD]"],
]);

test('redacts labelled card-like values regardless of grouping and luhn', function (string $label, string $card) {
    $result = (new SensitiveDataSanitizer)->sanitize("{$label}: {$card}. Почему отклонили чек?");

    expect($result->text)->toBe("{$label}: [REDACTED_PAYMENT_CARD]. Почему отклонили чек?");
    expect($result->wasRedacted)->toBeTrue();
    expect($result->redactionTypes)->toBe(['payment_card']);
})->with([
    'Russian card' => 'Карта',
    'Russian card number' => 'Номер карты',
    'English card' => 'card',
    'English card number' => 'card number',
])->with([
    'plain invalid Luhn' => '378282246310006',
    'space-separated invalid Luhn' => '3782 822463 10006',
    'hyphen-separated invalid Luhn' => '3782-822463-10006',
    'long first group' => '3782822463100 06',
    '13 digits' => '1234 56789 0123',
    '19 digits' => '1234 5678 9012 3456 789',
]);

test('preserves the existing card rules without a secret label', function (string $input, string $expected, array $types) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toBe($expected);
    expect($result->wasRedacted)->toBe($types !== []);
    expect($result->redactionTypes)->toBe($types);
})->with([
    'plain valid Luhn' => ['Оплата 378282246310005', 'Оплата [REDACTED_PAYMENT_CARD]', ['payment_card']],
    'space-separated valid Luhn' => ['Оплата 3782 822463 10005', 'Оплата [REDACTED_PAYMENT_CARD]', ['payment_card']],
    'hyphen-separated valid Luhn' => ['Оплата 3782-822463-10005', 'Оплата [REDACTED_PAYMENT_CARD]', ['payment_card']],
    'existing four-by-four exception' => ['Оплата 2200 1234 5678 9012', 'Оплата [REDACTED_PAYMENT_CARD]', ['payment_card']],
    'plain invalid Luhn' => ['Номер операции 378282246310006', 'Номер операции 378282246310006', []],
    'space-separated invalid Luhn' => ['Номер операции 3782 822463 10006', 'Номер операции 3782 822463 10006', []],
    'hyphen-separated invalid Luhn' => ['Номер операции 3782-822463-10006', 'Номер операции 3782-822463-10006', []],
]);

test('redacts cards without absorbing adjacent amounts', function (string $input, string $expected) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toBe($expected);
    expect($result->wasRedacted)->toBeTrue();
    expect($result->redactionTypes)->toBe(['payment_card']);
})->with([
    'review reproduction' => ['Карта 4111 1111 1111 1111 100 рублей', 'Карта [REDACTED_PAYMENT_CARD] 100 рублей'],
    'four digit amount' => ['Карта 4111 1111 1111 1111 1000 рублей', 'Карта [REDACTED_PAYMENT_CARD] 1000 рублей'],
    'amount before card' => ['100 рублей 100 4111 1111 1111 1111', '100 рублей 100 [REDACTED_PAYMENT_CARD]'],
    'grouped card without Luhn' => ['Карта 2200 1234 5678 9012 100 рублей', 'Карта [REDACTED_PAYMENT_CARD] 100 рублей'],
    'Unicode spaces' => ["Карта 4111\u{00A0}1111\u{00A0}1111\u{00A0}1111\u{00A0}100 рублей", "Карта [REDACTED_PAYMENT_CARD]\u{00A0}100 рублей"],
    'plain card then amount' => ['Карта 4111111111111111 100 рублей', 'Карта [REDACTED_PAYMENT_CARD] 100 рублей'],
    'amount then plain card' => ['Оплата 100 4111111111111111', 'Оплата 100 [REDACTED_PAYMENT_CARD]'],
    'two cards then amount' => ['Карты 4111 1111 1111 1111 2200 1234 5678 9012 100 рублей', 'Карты [REDACTED_PAYMENT_CARD] [REDACTED_PAYMENT_CARD] 100 рублей'],
    'other grouping then amount' => ['Карта 3782 822463 10005 100 рублей', 'Карта [REDACTED_PAYMENT_CARD] 100 рублей'],
    'amount then other grouping' => ['Оплата 10000 3782 822463 10005', 'Оплата 10000 [REDACTED_PAYMENT_CARD]'],
    'invalid Luhn other grouping then amount' => ['Карта 3782 822463 10006 100 рублей', 'Карта [REDACTED_PAYMENT_CARD] 100 рублей'],
    'invalid Luhn other grouping then large amount' => ['Карта 3782 822463 10006 10000 рублей', 'Карта [REDACTED_PAYMENT_CARD] 10000 рублей'],
    'invalid Luhn other grouping then English amount' => ['Card number: 3782-822463-10006 100 USD', 'Card number: [REDACTED_PAYMENT_CARD] 100 USD'],
    'invalid Luhn other grouping then ruble symbol' => ['Карта: 3782 822463 10006 100 ₽', 'Карта: [REDACTED_PAYMENT_CARD] 100 ₽'],
    'multiple spaces before amount' => ['Карта: 3782 822463 10006  100 рублей', 'Карта: [REDACTED_PAYMENT_CARD]  100 рублей'],
]);

test('redacts contextual otp values', function (string $input, string $secret) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toContain('[REDACTED_OTP]')->not->toContain($secret)
        ->and($result->redactionTypes)->toBe(['otp']);
})->with([
    'code from sms' => ['код из смс 123456', '123456'],
    'confirmation code' => ['код подтверждения: 123456', '123456'],
    'sms code' => ['sms код: 1234', '1234'],
    'otp' => ['otp 839201', '839201'],
    'letters and digits' => ['код из смс: A1B2C3', 'A1B2C3'],
    'starts with digits' => ['OTP: 1234AB', '1234AB'],
    'ends with a digit' => ['СМС-код: ABCDE1', 'ABCDE1'],
    'Cyrillic letters and digits' => ['одноразовый код: А1Б2В3', 'А1Б2В3'],
]);

test('redacts contextual passwords and preserves ambiguous unquoted phrases', function (string $input, string $secret, bool $redacted = true) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    if ($redacted) {
        expect($result->text)->toContain('[REDACTED_PASSWORD]')->not->toContain($secret)
            ->and($result->redactionTypes)->toBe(['password']);
    } else {
        expect($result->text)->toBe($input)->and($result->redactionTypes)->toBe([]);
    }
})->with([
    'russian password' => ['пароль: qwerty123', 'qwerty123'],
    'english password' => ['password=hunter2', 'hunter2'],
    'owned password' => ['мой пароль secret-word', 'secret-word'],
    'without colon' => ['пароль qwerty123', 'qwerty123'],
    'starts with exclamation' => ['пароль !secret', '!secret'],
    'starts with hash' => ['password #secret', '#secret'],
    'explicit reset password' => ['password: reset', 'reset'],
    'multiple words' => ['пароль: secret word', 'secret word'],
    'multiple words without declaration' => ['password secret word', 'secret word', false],
    'quoted punctuation' => ['пароль: "secret, word; 123"', 'secret, word; 123'],
]);

test('redacts complete spaced otp values while preserving surrounding text', function (string $input, string $expected) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toBe($expected);
    expect($result->wasRedacted)->toBeTrue();
    expect($result->redactionTypes)->toBe(['otp']);
})->with([
    'two groups' => ['код из смс: 123 456, проверьте вход', 'код из смс: [REDACTED_OTP], проверьте вход'],
    'individual digits' => ['OTP: 1 2 3 4 5 6', 'OTP: [REDACTED_OTP]'],
    'Unicode spaces' => ["СМС-код: 123\u{00A0}456", 'СМС-код: [REDACTED_OTP]'],
    'line boundary' => ["код из смс: 123456\n1000 баллов", "код из смс: [REDACTED_OTP]\n1000 баллов"],
    'value on next line' => ["код из смс:\n123 456\nНе могу войти", "код из смс:\n[REDACTED_OTP]\nНе могу войти"],
    'hyphenated SMS reproduction' => ['код из SMS: 123-456', 'код из SMS: [REDACTED_OTP]'],
    'hyphenated digits' => ['OTP: 1-2-3-4-5-6. Почему отклонили чек?', 'OTP: [REDACTED_OTP]. Почему отклонили чек?'],
    'mixed separators' => ['одноразовый код: 12 - 34 56, проверьте вход', 'одноразовый код: [REDACTED_OTP], проверьте вход'],
    'Unicode dash' => ['СМС-код: 123‑456; проверьте вход', 'СМС-код: [REDACTED_OTP]; проверьте вход'],
    'Unicode spaces and dash' => ["OTP: 123\u{202F}—\u{00A0}456", 'OTP: [REDACTED_OTP]'],
    'English SMS code' => ['SMS code: 123-456', 'SMS code: [REDACTED_OTP]'],
    'English OTP code' => ['OTP code: 123 456', 'OTP code: [REDACTED_OTP]'],
    'English code from SMS' => ['Code from SMS: 123456', 'Code from SMS: [REDACTED_OTP]'],
    'hyphenated alphanumeric code' => ['OTP: ABC-123, check login', 'OTP: [REDACTED_OTP], check login'],
    'spaced alphanumeric code' => ['SMS code: A1 B2 C3, check login', 'SMS code: [REDACTED_OTP], check login'],
    'alphanumeric code with separate letter groups' => ['OTP: AB CD 12, check login', 'OTP: [REDACTED_OTP], check login'],
    'alphanumeric code starting with digits' => ['OTP: 1234-AB; check login', 'OTP: [REDACTED_OTP]; check login'],
    'confirmation code with hyphens' => ['Код подтверждения: 123-456. Почему отклонили чек?', 'Код подтверждения: [REDACTED_OTP]. Почему отклонили чек?'],
    'confirmation code with spaces' => ['Код подтверждения 123 456; проверьте вход', 'Код подтверждения [REDACTED_OTP]; проверьте вход'],
    'bare OTP with spaces' => ['OTP 123 456', 'OTP [REDACTED_OTP]'],
]);

test('handles password values without changing explicit surrounding context', function (string $input, string $expected, array $types = ['password']) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toBe($expected);
    expect($result->redactionTypes)->toBe($types);
    if ($types !== []) {
        expect($result->text)->not->toBe($input);
    }
    expect((new SensitiveDataSanitizer)->sanitize($result->text)->text)->toBe($expected);
})->with([
    'comma boundary' => ['пароль: secret word, проверьте вход', 'пароль: [REDACTED_PASSWORD], проверьте вход'],
    'ambiguous phrase without declaration' => ['мой пароль secret word; проверьте вход', 'мой пароль secret word; проверьте вход', []],
    'line boundary' => ["пароль: secret word\nНе могу войти", "пароль: [REDACTED_PASSWORD]\nНе могу войти"],
    'ambiguous Unicode phrase' => ["пароль\u{00A0}secret\u{202F}word", "пароль\u{00A0}secret\u{202F}word", []],
    'Unicode value declaration' => ["пароль:\u{00A0}secret\u{202F}word", "пароль:\u{00A0}[REDACTED_PASSWORD]"],
    'bare value with digits' => ['пароль qwerty123', 'пароль [REDACTED_PASSWORD]'],
    'value formerly caught by reset whitelist' => ['Мой пароль reset-123', 'Мой пароль [REDACTED_PASSWORD]'],
    'owned alphabetic value' => ['Мой пароль qwerty', 'Мой пароль [REDACTED_PASSWORD]'],
    'owned reset value' => ['Мой пароль reset', 'Мой пароль [REDACTED_PASSWORD]'],
    'owned dotted value' => ['Мой пароль secret.word', 'Мой пароль [REDACTED_PASSWORD]'],
    'English owned alphabetic value' => ['My password qwerty', 'My password [REDACTED_PASSWORD]'],
    'owned pwd alias' => ['my pwd reset', 'my pwd [REDACTED_PASSWORD]'],
    'owned Cyrillic value' => ['мой пароль секрет', 'мой пароль [REDACTED_PASSWORD]'],
    'owned Unicode spaces' => ["Мой\u{00A0}пароль\u{202F}qwerty", "Мой\u{00A0}пароль\u{202F}[REDACTED_PASSWORD]"],
    'owned comma boundary' => ['Мой пароль qwerty, проверьте вход', 'Мой пароль [REDACTED_PASSWORD], проверьте вход'],
    'owned line boundary' => ["Мой пароль qwerty\nНе могу войти", "Мой пароль [REDACTED_PASSWORD]\nНе могу войти"],
    'owned trailing whitespace' => ['Мой пароль qwerty  ', 'Мой пароль [REDACTED_PASSWORD]  '],
    'owned trailing Unicode whitespace' => ["Мой пароль qwerty\u{00A0}", "Мой пароль [REDACTED_PASSWORD]\u{00A0}"],
    'owned quoted separators' => ['My password "secret, word; 123", check login', 'My password [REDACTED_PASSWORD], check login'],
    'owned declaration on next line' => ["Мой пароль :\nqwerty\nНе могу войти", "Мой пароль :\n[REDACTED_PASSWORD]\nНе могу войти"],
    'bare punctuation value' => ['пароль !abc', 'пароль [REDACTED_PASSWORD]'],
    'explicit phrase' => ['пароль: secret word', 'пароль: [REDACTED_PASSWORD]'],
    'quoted phrase' => ['пароль: "secret, word; 123", проверьте вход', 'пароль: [REDACTED_PASSWORD], проверьте вход'],
    'single quotes' => ["password: 'secret word'; check login", 'password: [REDACTED_PASSWORD]; check login'],
    'guillemets' => ['пароль: «секретные слова», проверьте вход', 'пароль: [REDACTED_PASSWORD], проверьте вход'],
    'punctuation in password' => ['пароль: secret.word!?', 'пароль: [REDACTED_PASSWORD]'],
    'value on next line' => ["пароль:\nsecret word\nНе могу войти", "пароль:\n[REDACTED_PASSWORD]\nНе могу войти"],
    'quoted Cyrillic without colon' => ['пароль «секретные слова»; проверьте вход', 'пароль [REDACTED_PASSWORD]; проверьте вход'],
    'Cyrillic declaration' => ['пароль: секретные слова; проверьте вход', 'пароль: [REDACTED_PASSWORD]; проверьте вход'],
    'Cyrillic with digits without colon' => ['пароль ёжик123; проверьте вход', 'пароль [REDACTED_PASSWORD]; проверьте вход'],
    'sentence boundary reproduction' => ['Пароль qwerty123. Почему отклонили чек?', 'Пароль [REDACTED_PASSWORD]. Почему отклонили чек?'],
    'declared sentence boundary' => ['Пароль: qwerty123. Почему отклонили чек?', 'Пароль: [REDACTED_PASSWORD]. Почему отклонили чек?'],
    'bare value before a question' => ['Password hunter2? Why was the receipt rejected?', 'Password [REDACTED_PASSWORD]? Why was the receipt rejected?'],
    'bare value before an instruction' => ['Пароль qwerty123 проверьте вход', 'Пароль [REDACTED_PASSWORD] проверьте вход'],
    'owned sentence boundary' => ['Мой пароль qwerty. Почему отклонили чек?', 'Мой пароль [REDACTED_PASSWORD]. Почему отклонили чек?'],
    'internal password punctuation' => ['Пароль secret.word123. Почему отклонили чек?', 'Пароль [REDACTED_PASSWORD]. Почему отклонили чек?'],
    'quoted sentence punctuation' => ['Пароль: "secret. word123". Почему отклонили чек?', 'Пароль: [REDACTED_PASSWORD]. Почему отклонили чек?'],
    'quoted value before an instruction' => ['Пароль: "qwerty123" проверьте вход', 'Пароль: [REDACTED_PASSWORD] проверьте вход'],
    'owned digit value' => ['мой пароль qwerty123. Почему отклонили чек?', 'мой пароль [REDACTED_PASSWORD]. Почему отклонили чек?'],
    'assigned dotted value before receipt context' => ['password=secret.word; receipt rejected', 'password=[REDACTED_PASSWORD]; receipt rejected'],
]);

test('preserves ordinary questions and instructions about passwords and sms codes', function (string $input) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toBe($input)
        ->and($result->wasRedacted)->toBeFalse()
        ->and($result->redactionTypes)->toBe([]);
})->with([
    'English reset question' => 'Password reset does not work',
    'English change question' => 'Password change does not work',
    'English forgotten statement' => 'Password forgotten, cannot log in',
    'English password problem' => 'My password does not work',
    'English recovery question' => 'Password recovery is unavailable',
    'Unicode dates and amounts' => 'Срок 01–31 октября, сумма 1000–3500 рублей',
    'password question from review' => 'Как поменять пароль в личном кабинете?',
    'sms statement from review' => 'Код из смс пришел вчера',
    'owned password problem' => 'Мой пароль не работает',
    'rejected password' => 'Пароль не принимается',
    'password recovery' => 'Не могу восстановить пароль после регистрации',
    'missing sms' => 'Код из смс не приходит',
    'delayed sms' => 'СМС-код приходит с задержкой',
    'expired code' => 'Одноразовый код истек',
    'operator instruction' => 'Измените пароль в личном кабинете. Код из смс приходит на указанный телефон.',
    'English change question with later digits' => 'Password change does not work after 2 attempts',
    'English missing OTP' => 'OTP code does not arrive',
    'English expired SMS code' => 'SMS code expired yesterday',
    'English delayed SMS code with later digits' => 'SMS code arrives after 10 minutes',
    'missing SMS with later digits' => 'Код из SMS не приходит уже 10 минут',
    'confirmation code question' => 'Код подтверждения не приходит',
    'confirmation code with a later date' => 'Код подтверждения не пришел 06.10.2026',
    'short OTP' => 'OTP 123',
]);

test('preserves a password question while redacting a later disclosed secret', function () {
    $result = (new SensitiveDataSanitizer)->sanitize('Как поменять пароль в личном кабинете? Мой пароль: qwerty123; код из смс пришел вчера, OTP: A1B2C3');

    expect($result->text)->toBe('Как поменять пароль в личном кабинете? Мой пароль: [REDACTED_PASSWORD]; код из смс пришел вчера, OTP: [REDACTED_OTP]')
        ->and($result->wasRedacted)->toBeTrue()
        ->and($result->redactionTypes)->toBe(['otp', 'password']);
});

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
    'password mentioned without a value' => 'Как восстановить пароль?',
    'unlabelled spaced digits' => 'Номер обращения 123 456',
    'unlabelled hyphenated digits' => 'Номер обращения 123-456',
    'unlabelled invalid card-like number' => 'Номер операции 2200123456789012',
    'invalid card-like receipt number' => 'Номер чека: 2200123456789012',
    'short card reference' => 'Карта заканчивается на 9012',
    'overlong labelled identifier' => 'Карта: 123456789012345678901',
    'amount with thousands separator' => 'Сумма 12 345 рублей',
    'six digit receipt' => 'Чек №123456',
    'raffle date' => 'Розыгрыш 06.10.2026',
    'email' => 'Мой email participant123@example.test',
    'tax identifier' => 'ИНН 7707083893',
    'passport' => 'Паспорт 4510 123456',
    'unlabelled security code' => 'Номер обращения 123',
    'short grouped card reference' => 'Карта: 12 345',
    'overlong grouped card reference' => 'Карта: 1234567890 1234567890',
]);

test('redacts only explicitly labelled card security codes', function (string $input, string $expected) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toBe($expected);
    expect($result->wasRedacted)->toBeTrue();
    expect($result->redactionTypes)->toBe(['cvv']);
})->with([
    'CVV reproduction' => ['CVV: 123', 'CVV: [REDACTED_CVV]'],
    'four digit CVV' => ['CVV 1234. Почему отклонили чек?', 'CVV [REDACTED_CVV]. Почему отклонили чек?'],
    'CVC with assignment' => ['CVC=123; receipt rejected', 'CVC=[REDACTED_CVV]; receipt rejected'],
    'lowercase CVC' => ['cvc: 1234, проверьте оплату', 'cvc: [REDACTED_CVV], проверьте оплату'],
    'Russian security code' => ['Код на обратной стороне карты: 123. Почему отклонили чек?', 'Код на обратной стороне карты: [REDACTED_CVV]. Почему отклонили чек?'],
    'security code on next line' => ["CVC:\n1234\nПочему отклонили чек?", "CVC:\n[REDACTED_CVV]\nПочему отклонили чек?"],
]);

test('preserves questions about security codes and values outside their declared length', function (string $input) {
    $result = (new SensitiveDataSanitizer)->sanitize($input);

    expect($result->text)->toBe($input);
    expect($result->wasRedacted)->toBeFalse();
    expect($result->redactionTypes)->toBe([]);
})->with([
    'CVV question' => 'Где найти CVV?',
    'CVC question' => 'CVC does not work',
    'Russian security code question' => 'Код на обратной стороне карты не нужен для поддержки',
    'short security code' => 'CVV: 12',
    'long security code' => 'CVC: 12345',
    'alphanumeric identifier' => 'CVV: 123ABC',
    'later ordinary number' => 'CVV не нужен, чек №123',
]);

test('reports redaction only for actual changes and remains unchanged on a second pass', function (string $input, string $expected, array $types) {
    $sanitizer = new SensitiveDataSanitizer;

    $result = $sanitizer->sanitize($input);
    $secondResult = $sanitizer->sanitize($result->text);

    expect($result->text)->toBe($expected);
    expect($result->wasRedacted)->toBe($expected !== $input);
    expect($result->redactionTypes)->toBe($types);
    expect($secondResult->text)->toBe($expected);
    expect($secondResult->wasRedacted)->toBeFalse();
    expect($secondResult->redactionTypes)->toBe([]);
})->with([
    'grouped card' => ['Карта: 3782 822463 10006', 'Карта: [REDACTED_PAYMENT_CARD]', ['payment_card']],
    'hyphen-separated card' => ['Карта: 3782-822463-10006', 'Карта: [REDACTED_PAYMENT_CARD]', ['payment_card']],
    'plain card' => ['Карта: 378282246310006', 'Карта: [REDACTED_PAYMENT_CARD]', ['payment_card']],
    'OTP' => ['код из SMS: 123-456', 'код из SMS: [REDACTED_OTP]', ['otp']],
    'password' => ['Пароль qwerty123. Почему отклонили чек?', 'Пароль [REDACTED_PASSWORD]. Почему отклонили чек?', ['password']],
    'CVV' => ['CVV: 123', 'CVV: [REDACTED_CVV]', ['cvv']],
    'already masked values' => ['Карта: [REDACTED_PAYMENT_CARD]; OTP: [REDACTED_OTP]; пароль: [REDACTED_PASSWORD]; CVV: [REDACTED_CVV]', 'Карта: [REDACTED_PAYMENT_CARD]; OTP: [REDACTED_OTP]; пароль: [REDACTED_PASSWORD]; CVV: [REDACTED_CVV]', []],
    'no secret' => ['Сумма 12 345 рублей; Чек №123456', 'Сумма 12 345 рублей; Чек №123456', []],
]);

test('metadata never contains detected secret values', function () {
    $secrets = ['2200 1234 5678 9012', '123456', 'qwerty123', '987'];
    $result = (new SensitiveDataSanitizer)->sanitize(
        'Карта 2200 1234 5678 9012, код из смс 123456, пароль: qwerty123; CVV: 987',
    );
    $metadata = json_encode($result->redactionTypes, JSON_THROW_ON_ERROR);

    expect($result->redactionTypes)->toBe(['payment_card', 'otp', 'cvv', 'password'])
        ->and($result->text)->not->toContain(...$secrets)
        ->and($metadata)->not->toContain(...$secrets);
});
