<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The band keys and the recommendation API must agree on how a resolution is
 * spelled.
 *
 * GET /builder/bands publishes "1080p", "1440p" and "4k". The recommendation
 * endpoint validated against "1080P", "1440P" and "4K". A client that read the
 * bands and passed the key straight through got a failed validation, which for a
 * non-JSON client is a 302 straight back to the homepage - a silent bounce
 * rather than an error. Anything calling this endpoint (aigen studio, the Genie)
 * has to be able to hand it a band key.
 */
class ResolutionVocabularyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider spellings
     */
    public function test_both_spellings_of_a_resolution_are_accepted(string $input, string $normalised): void
    {
        $response = $this->postJson('/api/build/recommend', [
            'budget' => 1500,
            'resolution' => $input,
        ]);

        $response->assertOk();
        $this->assertSame($normalised, $response->json('resolution'));
    }

    public static function spellings(): array
    {
        return [
            'bands key lower' => ['1080p', '1080P'],
            'bands key 1440' => ['1440p', '1440P'],
            'bands key 4k' => ['4k', '4K'],
            'api style upper' => ['1080P', '1080P'],
            'api style 4K' => ['4K', '4K'],
            'padded' => ['  1080p  ', '1080P'],
        ];
    }

    public function test_the_band_keys_this_feature_publishes_are_the_ones_the_api_accepts(): void
    {
        // Read the real bands endpoint and prove every key it publishes is a
        // spelling the recommendation API tolerates. This is the check that
        // would have caught the mismatch in the first place.
        $bands = $this->getJson('/builder/bands')->assertOk()->json('bands');
        $this->assertIsArray($bands, '/builder/bands must publish a bands object');

        foreach (array_keys($bands) as $key) {
            $response = $this->postJson('/api/build/recommend', [
                'budget' => 2000,
                'resolution' => $key,
            ]);
            $response->assertOk("band key '{$key}' was rejected by the recommendation API");
        }
    }

    public function test_a_nonsense_resolution_is_still_rejected(): void
    {
        // Case-normalising must not turn into case-ignoring: '720p' is still
        // not a band we sell.
        $this->postJson('/api/build/recommend', [
            'budget' => 1500,
            'resolution' => '720p',
        ])->assertStatus(422);
    }
}
