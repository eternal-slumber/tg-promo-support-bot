<?php

use App\Services\PromotionRules;

test('keeps multiline lists inside their numbered rule without including the next rule or heading', function () {
    $rules = "4.1. Участвуют йогурты:\n- питьевые;\n- густые.\n\n4.2. Кефир не участвует.\n\n## 5. Следующий раздел\n";

    expect((new PromotionRules)->catalog($rules))->toBe([
        '4.1' => "Участвуют йогурты:\n- питьевые;\n- густые.",
        '4.2' => 'Кефир не участвует.',
    ]);
});
