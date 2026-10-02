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

    /** @return array<string, string> */
    public function catalog(string $promotionRules): array
    {
        preg_match_all('/^(\d+\.\d+)\.\s+([\s\S]*?)(?=^\d+\.\d+\.\s|^## |\z)/m', $promotionRules, $matches, PREG_SET_ORDER);

        $catalog = [];

        foreach ($matches as $match) {
            $catalog[$match[1]] = trim($match[2]);
        }

        return $catalog;
    }

    public function systemPrompt(string $promotionRules): string
    {
        $template = file_get_contents(resource_path('prompts/support-system.md')) ?: throw new \RuntimeException('Support system prompt is unavailable.');

        return str_replace('{{PROMOTION_RULES}}', $promotionRules, $template);
    }
}
