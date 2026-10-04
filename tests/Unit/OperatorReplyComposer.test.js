import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

function mount(body = 'Ответ участнику', send) {
    const listeners = {};
    let createComposer;
    let watcher;
    const textarea = {
        value: body,
        style: {},
        scrollTop: 0,
        get scrollHeight() { return this.value.split('\n').length * 20 + 16; },
    };
    const submitted = [];

    runInNewContext(script, {
        document: {
            addEventListener(name, callback) { listeners[name] = callback; },
            querySelector() { return null; },
        },
        window: { Alpine: { data(name, callback) { createComposer = callback; } } },
        getComputedStyle() { return { lineHeight: '20px', paddingTop: '8px', paddingBottom: '8px' }; },
    });
    listeners['alpine:init']();

    const composer = createComposer();
    composer.$refs = { reply: textarea };
    composer.$el = { dataset: { replyBlocked: 'false' } };
    composer.$nextTick = async (callback) => callback?.();
    composer.$watch = (property, callback) => { watcher = callback; };
    composer.$wire = {
        get replyBody() { return textarea.value; },
        set replyBody(value) { textarea.value = value; },
        async sendReply() {
            submitted.push(this.replyBody);

            if (send) {
                await send(this);
            } else {
                this.replyBody = '';
            }
        },
    };
    composer.init();

    return { composer, textarea, submitted, poll: () => watcher() };
}

function key(overrides = {}) {
    return {
        key: 'Enter',
        prevented: false,
        preventDefault() { this.prevented = true; },
        ...overrides,
    };
}

test('Enter submits the current draft once and preserves multiline text', async () => {
    const ui = mount('Первая строка\nВторая строка');
    const event = key();
    await ui.composer.onKeydown(event);

    assert.equal(event.prevented, true);
    assert.deepEqual(ui.submitted, ['Первая строка\nВторая строка']);
    assert.equal(ui.composer.sendingBody, 'Первая строка\nВторая строка');
    assert.equal(ui.textarea.value, '');
});

test('Shift+Enter leaves the native newline behavior and draft untouched', () => {
    const ui = mount();
    const event = key({ shiftKey: true });
    ui.composer.onKeydown(event);

    assert.equal(event.prevented, false);
    assert.deepEqual(ui.submitted, []);
    assert.equal(ui.textarea.value, 'Ответ участнику');
});

test('modified keys and IME confirmation never submit a reply', () => {
    const ui = mount();

    for (const overrides of [{ ctrlKey: true }, { altKey: true }, { metaKey: true },
        { isComposing: true }, { keyCode: 229 }, { key: 'a' }]) {
        const event = key(overrides);
        ui.composer.onKeydown(event);
        assert.equal(event.prevented, false);
    }

    assert.deepEqual(ui.submitted, []);
});

test('holding Enter cannot resubmit even after the first response finishes', async () => {
    const ui = mount();
    await ui.composer.onKeydown(key());
    ui.textarea.value = 'Следующий черновик';
    const repeated = key({ repeat: true });
    await ui.composer.onKeydown(repeated);

    assert.equal(repeated.prevented, true);
    assert.deepEqual(ui.submitted, ['Ответ участнику']);
    assert.equal(ui.textarea.value, 'Следующий черновик');
});

for (const body of ['', '   ', '\n\t  \n']) {
    test(`blank draft ${JSON.stringify(body)} is blocked for both Enter and the button`, async () => {
        const ui = mount(body);
        await ui.composer.onKeydown(key());
        await ui.composer.submit();

        assert.deepEqual(ui.submitted, []);
        assert.equal(ui.composer.submitting, false);
        assert.equal(ui.textarea.value, body);
    });
}

for (const state of ['pending', 'failed']) {
    test(`${state} reply blocks Enter and the button without clearing the draft`, async () => {
        const ui = mount();
        ui.composer.$el.dataset.replyBlocked = 'true';
        await ui.composer.onKeydown(key());
        await ui.composer.submit();

        assert.deepEqual(ui.submitted, []);
        assert.equal(ui.composer.submitting, false);
        assert.equal(ui.textarea.value, 'Ответ участнику');

        ui.composer.$el.dataset.replyBlocked = 'false';
        await ui.composer.submit();
        assert.deepEqual(ui.submitted, ['Ответ участнику']);
    });
}

for (const first of ['Enter', 'button']) {
    test(`rapid Enter and a simultaneous click send only once when ${first} happens first`, async () => {
        let finish;
        const request = new Promise((resolve) => { finish = resolve; });
        const ui = mount('Один ответ', async (wire) => { await request; wire.replyBody = ''; });
        const pending = first === 'Enter' ? ui.composer.onKeydown(key()) : ui.composer.submit();

        assert.equal(ui.composer.submitting, true);
        await ui.composer.onKeydown(key());
        await ui.composer.submit();
        await ui.composer.onKeydown(key());
        assert.deepEqual(ui.submitted, ['Один ответ']);

        finish();
        await pending;
        assert.equal(ui.composer.submitting, false);
        assert.equal(ui.textarea.value, '');
    });
}

test('successful submit collapses a tall draft to one row', async () => {
    const ui = mount('1\n2\n3\n4\n5\n6\n7');
    assert.equal(ui.composer.height, '136px');
    assert.equal(ui.composer.overflow, 'auto');

    await ui.composer.submit();
    assert.equal(ui.composer.height, '36px');
    assert.equal(ui.textarea.style.height, '36px');
    assert.equal(ui.composer.overflow, 'hidden');
});

test('a validation rejection retains the draft and its height for correction', async () => {
    const ui = mount('Первая строка\nВторая строка', async () => {});
    await ui.composer.submit();

    assert.equal(ui.textarea.value, 'Первая строка\nВторая строка');
    assert.equal(ui.composer.height, '56px');
    assert.equal(ui.composer.submitting, false);
});

test('a failed request unlocks the composer without losing the draft', async () => {
    const ui = mount('Ответ', async () => { throw new Error('network error'); });
    await assert.rejects(ui.composer.submit(), /network error/);

    assert.equal(ui.textarea.value, 'Ответ');
    assert.equal(ui.composer.submitting, false);
});

test('finishing a request after leaving the conversation does not access a removed textarea', async () => {
    let finish;
    const request = new Promise((resolve) => { finish = resolve; });
    const ui = mount('Ответ', async () => request);
    const pending = ui.composer.submit();
    delete ui.composer.$refs.reply;
    finish();
    await pending;

    assert.deepEqual(ui.submitted, ['Ответ']);
    assert.equal(ui.composer.submitting, false);
});

test('grows from one row through six rows and scrolls only above that maximum', () => {
    const ui = mount('1');
    assert.equal(ui.composer.height, '36px');

    ui.textarea.value = '1\n2\n3';
    ui.composer.resize();
    assert.equal(ui.composer.height, '76px');
    assert.equal(ui.composer.overflow, 'hidden');

    ui.textarea.value = '1\n2\n3\n4\n5\n6';
    ui.composer.resize();
    assert.equal(ui.composer.height, '136px');
    assert.equal(ui.composer.overflow, 'hidden');

    ui.textarea.value += '\n7';
    ui.composer.resize();
    assert.equal(ui.composer.height, '136px');
    assert.equal(ui.composer.overflow, 'auto');

    ui.textarea.value = '1';
    ui.composer.resize();
    assert.equal(ui.composer.height, '36px');
    assert.equal(ui.composer.overflow, 'hidden');
});

test('refreshing a draft preserves its height and internal scroll position', async () => {
    const ui = mount('1\n2\n3\n4\n5\n6\n7');
    ui.textarea.scrollTop = 20;
    await ui.poll();
    await ui.poll();

    assert.equal(ui.composer.height, '136px');
    assert.equal(ui.textarea.style.height, '136px');
    assert.equal(ui.textarea.scrollTop, 20);
    assert.equal(ui.textarea.value, '1\n2\n3\n4\n5\n6\n7');
});
