<?php

namespace App\Services;

use App\Models\Component;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeminiService
{
    /**
     * Whether the Gemini provider is configured (GEMINI_API_KEY set).
     */
    public function available(): bool
    {
        return filled(config('gemini.key'));
    }

    /**
     * Ask Gemini for per-category scoring weights plus a short build rationale.
     *
     * Returns null whenever the provider is disabled, the request fails, or the
     * response is malformed, so callers always fall back to the heuristic.
     * Successful results are cached for six hours keyed by purpose + resolution.
     *
     * @param  array<string, Collection<int, Component>>  $pools
     * @return array{weights: array<string, float>, rationale: string}|null
     */
    public function scoreWeights(array $pools, float $budget, ?string $purpose = null, ?string $resolution = null): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $cacheKey = 'ai:gemini:weights:'.md5(($purpose ?? 'gaming').'|'.($resolution ?? '1440P'));

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $payload = $this->generate($pools, $budget, $purpose, $resolution);

        if ($payload === null) {
            return null;
        }

        $weights = $payload['weights'] ?? [];

        $result = [
            'weights' => is_array($weights)
                ? array_filter(array_map('floatval', $weights), fn (float $weight) => $weight > 0)
                : [],
            'rationale' => is_string($payload['rationale'] ?? null) ? $payload['rationale'] : '',
        ];

        Cache::put($cacheKey, $result, now()->addHours(6));

        return $result;
    }

    /**
     * Ask Gemini for a short, per-build rationale that explains WHY the actual
     * picked parts suit the user (CPU + GPU lead, socket/cooler sanity, value
     * and purpose). Returns null whenever the provider is disabled, the request
     * fails, or the response is malformed, so callers always degrade to the
     * strategy-level rationale (or none at all).
     *
     * Part NAMES only — no per-part prices, honouring the hidden-margin mandate.
     * Results are cached six hours keyed by the exact component set, so repeat
     * generations for the same build are free.
     *
     * @param  array<string, array{id: int, name: string}>  $components
     */
    public function describeBuild(array $components, float $budget, ?string $purpose = null, ?string $resolution = null): ?string
    {
        if (! $this->available()) {
            return null;
        }

        $ids = collect($components)->pluck('id')->filter()->sort()->implode('-');
        $cacheKey = 'ai:gemini:build:'.md5($ids.'|'.(int) round($budget).'|'.($purpose ?? 'gaming').'|'.($resolution ?? '1440P'));

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $lines = collect($components)->map(
            fn (array $item, string $category) => $category.': '.(string) ($item['name'] ?? '')
        )->implode("\n");

        $prompt = implode("\n", [
            'You are the PCTG PC configurator assistant. Explain this specific build to the customer in a friendly, knowledgeable, UK-English tone (no corporate jargon, no Americanisms).',
            '',
            'User context:',
            '- Budget: £'.number_format($budget),
            '- Purpose: '.($purpose ?? 'gaming'),
            '- Target resolution: '.($resolution ?? '1440P'),
            '',
            'The build chosen (part names only):',
            $lines,
            '',
            'Write 2-3 concise sentences: why these parts are a good fit for the purpose and resolution at this price point, leading with the CPU/GPU pairing, and noting anything genuinely notable (e.g. APU-only build = no discrete GPU, DDR5 platform, overspec look). Never invent specs or prices that are not listed. Never mention that PCTG applies a margin.',
            '',
            'Respond with ONLY valid JSON: {"rationale": "..."}',
        ]);

        $response = $this->call($prompt);

        if ($response === null) {
            return null;
        }

        $rationale = is_string($response['rationale'] ?? null) ? trim($response['rationale']) : '';

        if ($rationale === '') {
            return null;
        }

        Cache::put($cacheKey, $rationale, now()->addHours(6));

        return $rationale;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function call(string $prompt): ?array
    {
        try {
            $response = Http::timeout((int) config('gemini.timeout', 15))
                ->acceptJson()
                ->post($this->endpoint(), [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.4,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            if (! $response->successful()) {
                return null;
            }

            $text = $response->json('candidates.0.content.parts.0.text');

            if (! is_string($text) || trim($text) === '') {
                return null;
            }

            $decoded = json_decode($this->stripCodeFences($text), true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function generate(array $pools, float $budget, ?string $purpose, ?string $resolution): ?array
    {
        $prompt = implode("\n", [
            'You are the PCTG PC configurator engine. Recommend per-category scoring weights for building a complete, working gaming PC using current UK market pricing (GBP).',
            '',
            'User context:',
            '- Budget: £'.number_format($budget),
            '- Purpose: '.($purpose ?? 'gaming'),
            '- Target resolution: '.($resolution ?? '1440P'),
            '',
            'A complete, functional PC requires ALL of these categories: cpu, motherboard, cooler, gpu, ram, storage, psu, case. A build missing any one of them (e.g. no cooler or no motherboard) is NOT a working PC and must be avoided.',
            '',
            'Weight the categories so the picked parts stay within the budget while delivering the best real-world performance for the user\'s purpose and resolution at current market prices. Prefer the best value at the current price point — not the most expensive part. All weights must be between 0.5 and 1.5.',
            '',
            'Current UK market components and prices per category (this is the live catalog):',
            $this->summarise($pools),
            '',
            'Respond with ONLY valid JSON:',
            '{"weights": {"category": 1.0}, "rationale": "1-2 sentence build strategy referencing value-for-money and current market prices"}',
            'Weights should be between 0.5 and 1.5.',
        ]);

        return $this->call($prompt);
    }

    protected function endpoint(): string
    {
        return rtrim((string) config('gemini.base_url'), '/')
            .'/models/'.config('gemini.model', 'gemini-2.5-flash')
            .':generateContent?key='.config('gemini.key');
    }

    /**
     * @param  array<string, Collection<int, Component>>  $pools
     */
    protected function summarise(array $pools): string
    {
        $lines = [];

        foreach ($pools as $slug => $pool) {
            $rows = $pool->map(function (Component $component): string {
                $specs = is_array($component->specs) ? json_encode($component->specs) : '';

                return sprintf(
                    '- %s (£%s)%s',
                    $component->name,
                    number_format((float) $component->price),
                    $specs !== '' ? ' — '.$specs : ''
                );
            })->implode("\n");

            $lines[] = $slug.":\n".$rows;
        }

        return implode("\n\n", $lines);
    }

    protected function stripCodeFences(string $text): string
    {
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($text);
    }
}
