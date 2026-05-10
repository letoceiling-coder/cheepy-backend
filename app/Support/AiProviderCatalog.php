<?php

namespace App\Support;

/**
 * Каталог моделей и метаданные провайдеров.
 * Идентификаторы моделей сверяйте с официальной документацией — провайдеры регулярно добавляют версии.
 *
 * Источники (апрель 2026):
 * - OpenAI: https://platform.openai.com/docs/models
 * - Anthropic: https://platform.claude.com/docs/en/about-claude/models/overview
 * - Gemini: https://ai.google.dev/gemini-api/docs/models
 * - xAI: https://docs.x.ai/docs/models
 * - OpenRouter (OpenAI-совместимый): https://openrouter.ai/docs
 */
final class AiProviderCatalog
{
    private const PROVIDERS = [
        'openai' => [
            'title' => 'OpenAI',
            'description' => 'Чат и совместимые модели через OpenAI API; после сохранения ключа выберите модель из списка (актуальные id — в документации Models).',
            'docs_url' => 'https://platform.openai.com/docs/models',
            // Chat Completions / Responses: см. Models и Pricing на platform.openai.com
            'models' => [
                ['id' => 'gpt-5.5', 'label' => 'GPT-5.5'],
                ['id' => 'gpt-5.5-pro', 'label' => 'GPT-5.5 Pro'],
                ['id' => 'gpt-5.4', 'label' => 'GPT-5.4'],
                ['id' => 'gpt-5.4-mini', 'label' => 'GPT-5.4 Mini'],
                ['id' => 'gpt-5.4-nano', 'label' => 'GPT-5.4 Nano'],
                ['id' => 'gpt-4.1', 'label' => 'GPT-4.1'],
                ['id' => 'gpt-4.1-2025-04-14', 'label' => 'GPT-4.1 (snapshot 2025-04-14)'],
                ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 Mini'],
                ['id' => 'gpt-4.1-nano', 'label' => 'GPT-4.1 Nano'],
                ['id' => 'gpt-4o', 'label' => 'GPT-4o'],
                ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini'],
                ['id' => 'o3', 'label' => 'o3'],
                ['id' => 'o3-mini', 'label' => 'o3-mini'],
                ['id' => 'o4-mini', 'label' => 'o4-mini'],
            ],
        ],
        'anthropic' => [
            'title' => 'Anthropic (Claude)',
            'description' => 'Модели Claude через Messages API; список id и алиасов — в разделе Models overview.',
            'docs_url' => 'https://platform.claude.com/docs/en/about-claude/models/overview',
            'models' => [
                ['id' => 'claude-opus-4-7', 'label' => 'Claude Opus 4.7'],
                ['id' => 'claude-sonnet-4-6', 'label' => 'Claude Sonnet 4.6'],
                ['id' => 'claude-haiku-4-5', 'label' => 'Claude Haiku 4.5 (alias)'],
                ['id' => 'claude-haiku-4-5-20251001', 'label' => 'Claude Haiku 4.5 (20251001)'],
                ['id' => 'claude-opus-4-6', 'label' => 'Claude Opus 4.6'],
                ['id' => 'claude-sonnet-4-5', 'label' => 'Claude Sonnet 4.5 (alias)'],
                ['id' => 'claude-sonnet-4-5-20250929', 'label' => 'Claude Sonnet 4.5 (20250929)'],
                ['id' => 'claude-opus-4-5', 'label' => 'Claude Opus 4.5 (alias)'],
                ['id' => 'claude-opus-4-5-20251101', 'label' => 'Claude Opus 4.5 (20251101)'],
                ['id' => 'claude-opus-4-1', 'label' => 'Claude Opus 4.1 (alias)'],
                ['id' => 'claude-opus-4-1-20250805', 'label' => 'Claude Opus 4.1 (20250805)'],
            ],
        ],
        'gemini' => [
            'title' => 'Google AI Studio (Gemini)',
            'description' => 'Ключ из Google AI Studio; для generateContent используйте стабильные имена моделей из справочника Gemini API.',
            'docs_url' => 'https://ai.google.dev/gemini-api/docs/models',
            'models' => [
                ['id' => 'gemini-2.5-pro', 'label' => 'Gemini 2.5 Pro'],
                ['id' => 'gemini-2.5-flash', 'label' => 'Gemini 2.5 Flash'],
                ['id' => 'gemini-2.5-flash-lite', 'label' => 'Gemini 2.5 Flash-Lite'],
                ['id' => 'gemini-2.0-flash', 'label' => 'Gemini 2.0 Flash'],
            ],
        ],
        'xai' => [
            'title' => 'xAI (Grok)',
            'description' => 'Совместимо с OpenAI Chat Completions SDK; base URL и модели — в документации xAI.',
            'docs_url' => 'https://docs.x.ai/docs/models',
            'models' => [
                ['id' => 'grok-4-0709', 'label' => 'Grok 4 (id grok-4-0709, алиасы grok-4)'],
                ['id' => 'grok-4.20-0309-reasoning', 'label' => 'Grok 4.20 reasoning'],
                ['id' => 'grok-4.20-0309-non-reasoning', 'label' => 'Grok 4.20 non-reasoning'],
                ['id' => 'grok-4-1-fast-reasoning', 'label' => 'Grok 4.1 Fast reasoning'],
                ['id' => 'grok-4-1-fast-non-reasoning', 'label' => 'Grok 4.1 Fast non-reasoning'],
                ['id' => 'grok-code-fast-1', 'label' => 'Grok Code Fast 1'],
                ['id' => 'grok-3', 'label' => 'Grok 3'],
                ['id' => 'grok-3-mini', 'label' => 'Grok 3 Mini'],
            ],
        ],
        'replicate' => [
            'title' => 'Replicate',
            'description' => 'Официальные модели в формате owner/name (HTTP API predictions). Полный каталог — на replicate.com.',
            'docs_url' => 'https://replicate.com/docs',
            'models' => [
                ['id' => 'black-forest-labs/flux-2-dev', 'label' => 'FLUX.2 [dev]'],
                ['id' => 'black-forest-labs/flux-2-pro', 'label' => 'FLUX.2 [pro]'],
                ['id' => 'black-forest-labs/flux-schnell', 'label' => 'FLUX Schnell'],
                ['id' => 'black-forest-labs/flux-dev', 'label' => 'FLUX.1 [dev]'],
                ['id' => 'black-forest-labs/flux-1.1-pro', 'label' => 'FLUX 1.1 [pro]'],
                ['id' => 'meta/meta-llama-3-70b-instruct', 'label' => 'Llama 3 70B Instruct'],
                ['id' => 'google/imagen-4-ultra', 'label' => 'Google Imagen 4 Ultra'],
                ['id' => 'bytedance/seedance-1-pro', 'label' => 'ByteDance Seedance 1 Pro'],
            ],
        ],
        // Ollama behind our HTTPS tunnel: OpenAI-compatible POST …/v1/chat/completions.
        'ollama' => [
            'title' => 'Ollama',
            'description' => 'OpenAI-compatible Ollama через reverse SSH tunnel; укажите Base URL https://ollama.siteaacess.store/v1, Bearer Token и модель из /v1/models.',
            'docs_url' => 'https://ollama.siteaacess.store/v1/models',
            'models' => [
                ['id' => 'qwen2.5-coder:7b', 'label' => 'Qwen 2.5 Coder 7B'],
                ['id' => 'qwen2.5vl:7b', 'label' => 'Qwen 2.5 VL 7B'],
                ['id' => 'llama3:latest', 'label' => 'Llama 3'],
            ],
        ],
        'openrouter' => [
            'title' => 'OpenRouter',
            'description' => 'Единый OpenAI-compatible API ко множеству моделей. Ключ с openrouter.ai; полный список — кнопка «Обновить» (суффикс :free или нулевая текстовая цена → пометка «бесплатно»).',
            'docs_url' => 'https://openrouter.ai/docs/quickstart',
            'models' => [
                ['id' => 'meta-llama/llama-3.3-8b-instruct:free', 'label' => 'Llama 3.3 8B Instruct (free)'],
            ],
        ],
    ];

    /**
     * Провайдеры, которыми можно пользоваться как источником текста для CRM-агента (чат).
     * Replicate здесь нет — это в основном predictions/медиа, не чат для описаний.
     *
     * @return list<string>
     */
    public static function agentChatProviderKeys(): array
    {
        return array_values(array_diff(self::providerKeys(), ['replicate']));
    }

    public static function providerKeys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function meta(string $name): ?array
    {
        return self::PROVIDERS[$name] ?? null;
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    public static function models(string $name): array
    {
        return self::PROVIDERS[$name]['models'] ?? [];
    }

    public static function defaultModel(string $name): string
    {
        $models = self::models($name);

        return $models[0]['id'] ?? '';
    }

    public static function isValidModel(string $name, string $modelId): bool
    {
        $modelId = trim($modelId);
        if ($modelId === '') {
            return false;
        }
        if ($name === 'ollama' || $name === 'openrouter') {
            return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:\\/-]{0,191}$/', $modelId) === 1;
        }
        foreach (self::models($name) as $m) {
            if ($m['id'] === $modelId) {
                return true;
            }
        }

        return false;
    }
}
