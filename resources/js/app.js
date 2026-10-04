document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-theme-toggle]')) {
        return;
    }

    const dark = document.documentElement.classList.toggle('dark');

    try {
        localStorage.setItem('operator-theme', dark ? 'dark' : 'light');
    } catch {
        // The switch still works when the browser disables local storage.
    }
});

const dashboard = document.querySelector('[data-operator-dashboard]');

if (dashboard) {
    let chatState = null;

    const isNearBottom = (chat) => chat.scrollHeight - chat.clientHeight - chat.scrollTop <= 80;

    const syncConversationScroll = () => {
        const chat = dashboard.querySelector('[data-ticket-chat]');

        if (!chat) {
            chatState = null;
            return;
        }

        if (chatState?.element !== chat) {
            chatState = {
                element: chat,
                position: chat.scrollTop,
                nearBottom: true,
                latestMessageId: Number(chat.dataset.latestMessageId),
                initial: true,
                newMessage: false,
                frame: null,
            };

            chat.addEventListener('scroll', () => {
                if (chatState?.element === chat) {
                    chatState.position = chat.scrollTop;
                    chatState.nearBottom = isNearBottom(chat);
                }
            }, { passive: true });
        }

        const state = chatState;
        const latestMessageId = Number(chat.dataset.latestMessageId);
        state.newMessage ||= latestMessageId > state.latestMessageId;
        state.latestMessageId = latestMessageId;

        if (state.frame !== null) {
            return;
        }

        state.frame = requestAnimationFrame(() => {
            if (chatState !== state) {
                return;
            }

            chat.scrollTop = state.initial || (state.newMessage && state.nearBottom)
                ? chat.scrollHeight : state.position;
            state.position = chat.scrollTop;
            state.nearBottom = isNearBottom(chat);
            state.initial = false;
            state.newMessage = false;
            state.frame = null;
        });
    };

    new MutationObserver(syncConversationScroll).observe(dashboard, {
        childList: true,
        subtree: true,
        characterData: true,
        attributes: true,
        attributeFilter: ['data-latest-message-id'],
    });

    syncConversationScroll();
}
