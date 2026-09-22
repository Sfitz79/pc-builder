<?php

namespace Tests\Unit;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['gemini.key' => 'test-key', 'gemini.base_url' => 'https://example.test/v1beta', 'gemini.model' => 'gemini-3.6-flash']);
        Cache::flush();
    }

    public function test_unavailable_without_key(): void
    {
        config(['gemini.key' => null]);

        $this->assertFalse(app(GeminiService::class)->available());
    }

    public function test_available_when_key_set(): void
    {
        $this->assertTrue(app(GeminiService::class)->available());
    }

    public function test_describe_build_parses_rationale_and_caches(): void
    {
        Http::fake([
            'https://example.test/v1beta/models/gemini-3.6-flash:generateContent*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => '{"rationale": "The RTX 5070 keeps 1440p fps high while the 9600X stays cool."}']],
                        ],
                    ],
                ],
            ]),
        ]);

        $components = [
            'cpu' => ['id' => 1, 'name' => 'AMD Ryzen 5 9600X'],
            'gpu' => ['id' => 2, 'name' => 'NVIDIA RTX 5070 12GB'],
            'ram' => ['id' => 3, 'name' => '32GB DDR5 6000'],
        ];

        $service = app(GeminiService::class);
        $rationale = $service->describeBuild($components, 1400, 'gaming', '1440P');

        $this->assertSame('The RTX 5070 keeps 1440p fps high while the 9600X stays cool.', $rationale);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'generateContent'));

        // Second call hits the cache — no new HTTP request.
        $this->assertSame($rationale, $service->describeBuild($components, 1400, 'gaming', '1440P'));
        Http::assertSentCount(1);
    }

    public function test_describe_build_returns_null_on_http_error(): void
    {
        Http::fake([
            'https://example.test/v1beta/models/gemini-3.6-flash:generateContent*' => Http::response('error', 429),
        ]);

        $this->assertNull(app(GeminiService::class)->describeBuild(
            ['gpu' => ['id' => 2, 'name' => 'NVIDIA RTX 5070 12GB']],
            1400,
            'gaming',
            '1440P'
        ));
    }

    public function test_describe_build_returns_null_when_unavailable(): void
    {
        config(['gemini.key' => null]);

        $this->assertNull(app(GeminiService::class)->describeBuild(
            ['gpu' => ['id' => 2, 'name' => 'NVIDIA RTX 5070 12GB']],
            1400
        ));
    }
}