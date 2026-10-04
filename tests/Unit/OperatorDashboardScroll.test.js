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
        querySelector() { return { getClientRects: () => this.localReplyVisible ? [{}] : [] }; },
        get scrollTop() { return position; },
        set scrollTop(value) { position = Math.max(0, Math.min(value, this.scrollHeight - this.clientHeight)); },
        addEventListener(name, callback) { listeners[name] = callback; },
        scrollTo(value) { this.scrollTop = value; listeners.scroll(); },
    };
}

function mount(chat = createChat()) {
    let currentChat = chat;
    let notify;
    const frames = [];
    const dashboard = { querySelector: () => currentChat };

    runInNewContext(script, {
        document: {
            addEventListener() {},
            querySelector: () => dashboard,
        },
        MutationObserver: class {
            constructor(callback) { notify = callback; }
            observe() {}
        },
        requestAnimationFrame(callback) { frames.push(callback); return frames.length; },
    });

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
        poll(messageId, height) {
            currentChat.dataset.latestMessageId = String(messageId);
            currentChat.scrollHeight = height;
            notify();
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

test('follows a new message at the 80 pixel boundary', () => {
    const ui = mount();
    ui.chat.scrollTo(520);
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
    ui.chat.scrollTo(519);
    ui.poll(2, 1200);
    ui.poll(3, 1400);
    assert.equal(ui.chat.scrollTop, 519);
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
