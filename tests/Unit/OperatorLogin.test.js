import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

function mount(value, start = value.length, end = start) {
    const listeners = {};
    let createLogin;

    runInNewContext(script, {
        document: {
            addEventListener(name, callback) { listeners[name] = callback; },
            querySelector() { return null; },
        },
        window: {
            Alpine: {
                data(name, callback) {
                    if (name === 'operatorLogin') {
                        createLogin = callback;
                    }
                },
            },
        },
    });
    listeners['alpine:init']();

    const input = {
        value,
        selectionStart: start,
        selectionEnd: end,
        selectionDirection: 'backward',
        setSelectionRange(start, end, direction) {
            this.selectionStart = start;
            this.selectionEnd = end;
            this.selectionDirection = direction;
        },
    };
    const login = createLogin();
    login.$refs = { password: input };

    return { login, input };
}

for (const value of [
    '  Correct-Pass.2026!  ',
    'Correct- Pass.20 26!',
    '\tCorrect-\nPass.2026!\r\n',
    '\u00a0Correct-\u202fPass.2026!\u00a0',
    'Correct-\u2003Pass.20\u300026!',
    '\ufeffCorrect-Pass.2026!',
]) {
    test(`removes pasted whitespace throughout the password ${JSON.stringify(value)}`, () => {
        const ui = mount(value);
        ui.login.normalizePassword();

        assert.equal(ui.input.value, 'Correct-Pass.2026!');
        assert.equal(ui.input.selectionStart, 18);
    });
}

test('removes a typed space without moving the cursor to the end of the password', () => {
    const ui = mount('abc def', 4);
    ui.login.normalizePassword();

    assert.equal(ui.input.value, 'abcdef');
    assert.equal(ui.input.selectionStart, 3);
    assert.equal(ui.input.selectionEnd, 3);
});

test('preserves the remaining selected characters and selection direction after cleaning', () => {
    const ui = mount(' ab cd ef ', 2, 7);
    ui.login.normalizePassword();

    assert.equal(ui.input.value, 'abcdef');
    assert.equal(ui.input.selectionStart, 1);
    assert.equal(ui.input.selectionEnd, 4);
    assert.equal(ui.input.selectionDirection, 'backward');
});

test('leaves non-whitespace characters, case and an unchanged selection intact', () => {
    const value = 'Пароль-A.b_2026!';
    const ui = mount(value, 2, 6);
    ui.login.normalizePassword();
    ui.login.normalizePassword();

    assert.equal(ui.input.value, value);
    assert.equal(ui.input.selectionStart, 2);
    assert.equal(ui.input.selectionEnd, 6);
});

test('a whitespace-only password becomes empty instead of a separator or hidden credential', () => {
    const ui = mount(' \t\n\u00a0\u202f\ufeff');
    ui.login.normalizePassword();

    assert.equal(ui.input.value, '');
    assert.equal(ui.input.selectionStart, 0);
});

test('normalizes an autofilled value when the form submits without an input event', () => {
    const ui = mount('Original');
    ui.input.value = 'Correct- Pass.20 26! ';
    ui.input.selectionStart = ui.input.selectionEnd = ui.input.value.length;
    ui.login.normalizePassword();

    assert.equal(ui.input.value, 'Correct-Pass.2026!');
});
