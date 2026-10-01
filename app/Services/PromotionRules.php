<?php

namespace App\Services;

class PromotionRules
{
    public function content(): string
    {
        return file_get_contents(base_path('docs/assignment/promo-rules.md')) ?: throw new \RuntimeException('Promotion rules are unavailable.');
    }

    public function hash(): string
    {
        return hash('sha256', $this->content());
    }

    public function systemPrompt(string $promotionRules, string $participantMessage): string
    {
        $template = file_get_contents(resource_path('prompts/support-system.md')) ?: throw new \RuntimeException('Support system prompt is unavailable.');

        return str_replace(
            ['{{PROMOTION_RULES}}', '{{USER_MESSAGE}}'],
            [$promotionRules, $participantMessage],
            $template,
        );
    }
}
