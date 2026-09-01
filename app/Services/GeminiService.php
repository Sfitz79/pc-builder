<?php

namespace App\Services;

use App\Models\Component;
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
     * @param  array<string, \Illuminate\Support\Collection<int, \App\Models\Component>>  $pools
     * @return array{weights: array<string, float>, rationale: string}|null
     */
    public function scoreWeights(array $pools, float $budget, ?string $purpose = null, ?string $resolution = null): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $cacheKey = 'ai:gemini:weights:' . md5(($purpose ?? 'gaming') . '|' . ($resolution ?? '1440P'));

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
     * @param  array<string, \Illuminate\Support\Collection<int, \App\Models\Component>>  $pools
     * @return array<string, mixed>|null
     */
    protected function generate(array $pools, float $budget, ?string $purpose, ?string $resolution): ?array
    {
        $prompt = implode("\n", [
            'You are the PCTG PC configurator engine. Recommend per-category scoring weights for a gaming PC build.',
            '',
            'User context:',
            '- Budget: £' . number_format($budget),
            '- Purpose: ' . ($purpose ?? 'gaming'),
            '- Target resolution: ' . ($resolution ?? '1440P'),
            '',
            'Available components per category (name, price, specs):',
            $this->summarise($pools),
            '',
            'Respond with ONLY valid JSON:',
            '{"weights": {"category": 1.0}, "rationale": "1-2 sentence build strategy"}',
            'Weights should be between 0.5 and 1.5.',
        ]);

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

    protected function endpoint(): string
    {
        return rtrim((string) config('gemini.base_url'), '/')
            . '/models/' . config('gemini.model', 'gemini-2.5-flash')
            . ':generateContent?key=' . config('gemini.key');
    }

    /**
     * @param  array<string, \Illuminate\Support\Collection<int, \App\Models\Component>>  $pools
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
                    $specs !== '' ? ' — ' . $specs : ''
                );
            })->implode("\n");

            $lines[] = $slug . ":\n" . $rows;
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
