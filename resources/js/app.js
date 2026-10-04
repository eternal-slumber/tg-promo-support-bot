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

document.addEventListener('alpine:init', () => {
    window.Alpine.data('operatorReplyComposer', () => ({
        submitting: false,
        height: '36px',
        overflow: 'hidden',

        init() {
            this.$nextTick(() => this.resize());
            this.$watch('$wire.replyBody', () => this.$nextTick(() => this.resize()));
        },

        resize() {
            const textarea = this.$refs.reply;

            if (!textarea) {
                return;
            }

            const scrollTop = textarea.scrollTop;
            const style = getComputedStyle(textarea);
            const padding = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
            const lineHeight = parseFloat(style.lineHeight);
            const minimum = lineHeight + padding;
            const maximum = lineHeight * 6 + padding;

            textarea.style.height = 'auto';
            this.height = `${Math.min(maximum, Math.max(minimum, textarea.scrollHeight))}px`;
            this.overflow = textarea.scrollHeight > maximum ? 'auto' : 'hidden';
            textarea.style.height = this.height;
            textarea.style.overflowY = this.overflow;
            textarea.scrollTop = scrollTop;
        },

        onKeydown(event) {
            if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey
                || event.isComposing || event.keyCode === 229) {
                return;
            }

            event.preventDefault();

            if (!event.repeat) {
                return this.submit();
            }
        },

        async submit() {
            const body = this.$refs.reply.value;

            if (this.submitting || this.$el.dataset.replyBlocked === 'true' || !body.trim()) {
                return;
            }

            this.submitting = true;
            this.sendingBody = body;
            this.$wire.replyBody = body;
            const chat = this.$refs.chat;
            let submitted = false;

            try {
                await this.$wire.sendReply();
                submitted = this.$wire.replyBody === '';
            } finally {
                await this.$nextTick();
                this.submitting = false;
                this.resize();
                await this.$nextTick();

                if (submitted && chat && this.$refs.chat === chat) {
                    this.$dispatch('operator-reply-submitted', { chat });
                }
            }
        },
    }));
});

const dashboard = document.querySelector('[data-operator-dashboard]');

if (dashboard) {
    let chatState = null;

    const isNearBottom = (chat) => chat.scrollHeight - chat.clientHeight - chat.scrollTop < 100;

    const syncConversationScroll = () => {
        const chat = dashboard.querySelector('[data-ticket-chat]');

        if (!chat) {
            chatState = null;
            return;
        }

        const localReplyVisible = (chat.querySelector('[data-local-reply]')?.getClientRects().length ?? 0) > 0;

        if (chatState?.element !== chat) {
            chatState = {
                element: chat,
                position: chat.scrollTop,
                nearBottom: true,
                latestMessageId: Number(chat.dataset.latestMessageId),
                localReplyVisible: false,
                initial: true,
                newMessage: false,
                forceBottom: false,
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
        state.newMessage ||= latestMessageId > state.latestMessageId || (localReplyVisible && !state.localReplyVisible);
        state.latestMessageId = latestMessageId;
        state.localReplyVisible = localReplyVisible;

        if (state.frame !== null) {
            return;
        }

        state.frame = requestAnimationFrame(() => {
            if (chatState !== state) {
                return;
            }

            if (state.initial || state.forceBottom || (state.newMessage && state.nearBottom)) {
                chat.scrollTo({ top: chat.scrollHeight, behavior: state.initial ? 'auto' : 'smooth' });
            } else if (chat.scrollTop !== state.position) {
                chat.scrollTop = state.position;
            }

            state.position = chat.scrollTop;
            state.nearBottom = isNearBottom(chat);
            state.initial = false;
            state.newMessage = false;
            state.forceBottom = false;
            state.frame = null;
        });
    };

    document.addEventListener('livewire:init', () => {
        window.Livewire.interceptMessage(({ message, onSuccess }) => {
            if (message.component.el !== dashboard) {
                return;
            }

            onSuccess(({ onMorphed }) => {
                if (chatState) {
                    chatState.position = chatState.element.scrollTop;
                    chatState.nearBottom = isNearBottom(chatState.element);
                }

                onMorphed(syncConversationScroll);
            });
        });
    });

    dashboard.addEventListener('operator-reply-submitted', (event) => {
        if (chatState?.element === event.detail.chat) {
            chatState.forceBottom = true;
            syncConversationScroll();
        }
    });

    new MutationObserver(syncConversationScroll).observe(dashboard, {
        childList: true,
        subtree: true,
        characterData: true,
        attributes: true,
        attributeFilter: ['data-latest-message-id', 'style'],
    });

    syncConversationScroll();
}
