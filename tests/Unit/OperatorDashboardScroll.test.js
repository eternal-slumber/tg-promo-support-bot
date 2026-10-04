import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

function createChat(messageId = 1, height = 1000) {
    let position = 0;
    const listeners = {};

    return {
        dataset: { latestMessageId: String(messageId) },
        clientHeight: 400,
        scrollHeight: height,
        localReplyVisible: false,
        scrollCalls: [],
        deferSmooth: false,
        querySelector() { return { getClientRects: () => this.localReplyVisible ? [{}] : [] }; },
        get scrollTop() { return position; },
        set scrollTop(value) { position = Math.max(0, Math.min(value, this.scrollHeight - this.clientHeight)); },
        addEventListener(name, callback) { listeners[name] = callback; },
        scrollTo(value) {
            if (typeof value === 'object') {
                this.scrollCalls.push({ ...value });

                if (this.deferSmooth && value.behavior === 'smooth') {
                    return;
                }

                value = value.top;
            }

            this.scrollTop = value;
            listeners.scroll();
        },
    };
}

function mount(chat = createChat()) {
    let currentChat = chat;
    let notify;
    let intercept;
    const listeners = {};
    const dashboardListeners = {};
    const frames = [];
    const dashboard = {
        querySelector: () => currentChat,
        addEventListener(name, callback) { dashboardListeners[name] = callback; },
    };

    runInNewContext(script, {
        document: {
            addEventListener(name, callback) { listeners[name] = callback; },
            querySelector: () => dashboard,
        },
        window: { Livewire: { interceptMessage(callback) { intercept = callback; } } },
        MutationObserver: class {
            constructor(callback) { notify = callback; }
            observe() {}
        },
        requestAnimationFrame(callback) { frames.push(callback); return frames.length; },
    });
    listeners['livewire:init']();

    const beforeUpdate = (element = dashboard) => {
        let success;
        let morphed = () => {};
        intercept({ message: { component: { el: element } }, onSuccess(callback) { success = callback; } });
        success?.({ onMorphed(callback) { morphed = callback; } });
        return () => morphed();
    };

    const flush = () => {
        while (frames.length) {
            frames.shift()();
        }
    };
    flush();

    return {
        chat,
        notify: () => notify(),
        flush,
        beforeUpdate,
        submitted(sentChat = currentChat) { dashboardListeners['operator-reply-submitted']({ detail: { chat: sentChat } }); },
        poll(messageId, height) {
            const afterMorph = beforeUpdate();
            currentChat.dataset.latestMessageId = String(messageId);
            currentChat.scrollHeight = height;
            notify();
            afterMorph();
            flush();
        },
        replace(nextChat) { currentChat = nextChat; notify(); flush(); },
    };
}

test('opens the selected conversation at its latest message', () => {
    const { chat } = mount();
    assert.equal(chat.scrollTop, 600);
});

test('keeps scroll position when polling changes delivery text without adding a message', () => {
    const ui = mount();
    ui.poll(1, 1100);
    assert.equal(ui.chat.scrollTop, 600);
});

test('follows a new message while at the bottom', () => {
    const ui = mount();
    ui.poll(2, 1200);
    assert.equal(ui.chat.scrollTop, 800);
});

test('follows a new message within 100 pixels of the bottom', () => {
    const ui = mount();
    ui.chat.scrollTo(501);
    ui.poll(2, 1200);
    assert.equal(ui.chat.scrollTop, 800);
});

test('follows the local sending bubble only when the reader was near the bottom', () => {
    const ui = mount();
    ui.chat.localReplyVisible = true;
    ui.poll(1, 1100);
    assert.equal(ui.chat.scrollTop, 700);
});

test('keeps the reader position when the local sending bubble appears while reading history', () => {
    const ui = mount();
    ui.chat.scrollTo(200);
    ui.chat.localReplyVisible = true;
    ui.poll(1, 1100);
    assert.equal(ui.chat.scrollTop, 200);
});

test('does not treat a visible sending bubble as a new message on repeated delivery checks', () => {
    const ui = mount();
    ui.chat.localReplyVisible = true;
    ui.poll(1, 1100);
    ui.poll(1, 1200);
    assert.equal(ui.chat.scrollTop, 700);
    ui.chat.localReplyVisible = false;
    ui.poll(1, 1100);
    assert.equal(ui.chat.scrollTop, 700);
});

test('preserves the position while reading above the bottom boundary across repeated polls', () => {
    const ui = mount();
    ui.chat.scrollTo(500);
    ui.poll(2, 1200);
    ui.poll(3, 1400);
    assert.equal(ui.chat.scrollTop, 500);
});

test('does not scroll down when a message is removed or updated', () => {
    const ui = mount(createChat(10));
    ui.chat.scrollTo(550);
    ui.poll(9, 1100);
    assert.equal(ui.chat.scrollTop, 550);
});

test('respects a reader who scrolls up before a pending frame applies a new message', () => {
    const ui = mount();
    ui.chat.dataset.latestMessageId = '2';
    ui.chat.scrollHeight = 1200;
    ui.notify();
    ui.chat.scrollTo(200);
    ui.flush();
    assert.equal(ui.chat.scrollTop, 200);
});

test('keeps a new-message update when multiple morph changes are batched into one frame', () => {
    const ui = mount();
    ui.chat.dataset.latestMessageId = '2';
    ui.chat.scrollHeight = 1200;
    ui.notify();
    ui.notify();
    ui.flush();
    assert.equal(ui.chat.scrollTop, 800);
});

test('starts another selected conversation at its own bottom', () => {
    const ui = mount();
    ui.chat.scrollTo(100);
    const nextChat = createChat(20, 1500);
    ui.replace(nextChat);
    assert.equal(nextChat.scrollTop, 1100);
    assert.equal(ui.chat.scrollTop, 100);
});

test('ignores a queued update after leaving the ticket conversation', () => {
    const ui = mount();
    ui.chat.dataset.latestMessageId = '2';
    ui.chat.scrollHeight = 1200;
    ui.notify();
    ui.replace(null);
    assert.equal(ui.chat.scrollTop, 600);
});

test('an accepted operator reply scrolls to the rendered bubble even from old history', () => {
    const ui = mount();
    ui.chat.scrollTo(100);
    ui.poll(2, 1400);
    assert.equal(ui.chat.scrollTop, 100);

    ui.submitted();
    assert.equal(ui.chat.scrollTop, 100);
    ui.chat.clientHeight = 500;
    ui.flush();

    assert.equal(ui.chat.scrollTop, 900);
    assert.deepEqual(ui.chat.scrollCalls.at(-1), { top: 1400, behavior: 'smooth' });
    ui.chat.scrollTo(200);
    ui.poll(2, 1400);
    assert.equal(ui.chat.scrollTop, 200);
});

test('uses the position before the DOM update when new content changes the bottom distance', () => {
    const ui = mount();
    ui.chat.clientHeight = 350;
    const afterMorph = ui.beforeUpdate();
    ui.chat.dataset.latestMessageId = '2';
    ui.chat.scrollHeight = 1600;
    afterMorph();
    assert.equal(ui.chat.scrollTop, 600);
    ui.flush();

    assert.equal(ui.chat.scrollTop, 1250);
    assert.equal(ui.chat.scrollCalls.at(-1).behavior, 'smooth');
});

test('captures a viewport resize before polling instead of using a stale near-bottom flag', () => {
    const ui = mount();
    ui.chat.clientHeight = 200;
    ui.poll(2, 1400);

    assert.equal(ui.chat.scrollTop, 600);
    assert.equal(ui.chat.scrollCalls.length, 1);
});

test('unrelated Livewire updates do not capture or scroll this conversation', () => {
    const ui = mount();
    const afterMorph = ui.beforeUpdate({});
    ui.chat.scrollHeight = 1600;
    afterMorph();
    ui.flush();

    assert.equal(ui.chat.scrollTop, 600);
    assert.equal(ui.chat.scrollCalls.length, 1);
});

test('ignores a late reply event for a ticket that is no longer selected', () => {
    const ui = mount();
    const nextChat = createChat(20, 1500);
    ui.replace(nextChat);
    nextChat.scrollTo(200);
    ui.submitted(ui.chat);
    ui.flush();

    assert.equal(nextChat.scrollTop, 200);
    assert.equal(nextChat.scrollCalls.length, 1);
});

test('a delivery-only mutation does not interrupt a smooth conversation scroll', () => {
    const ui = mount();
    ui.chat.deferSmooth = true;
    ui.poll(2, 1400);
    ui.chat.scrollTo(750);
    ui.notify();
    ui.flush();

    assert.equal(ui.chat.scrollTop, 750);
    assert.equal(ui.chat.scrollCalls.length, 2);
});
