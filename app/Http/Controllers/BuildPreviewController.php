<?php

namespace App\Http\Controllers;

use App\Models\Component;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BUILD PREVIEW IMAGE - an auto-generated picture of the finished system.
 *
 * WHY THIS EXISTS (Boss directive 2026-10-13)
 * -------------------------------------------
 * The interactive 3D viewport project failed and is withdrawn. That also removed the only
 * thing the old storefront-render bridge could use: the 3D geometry was captured in the
 * browser as a data-URL and THAT was what got sent to the render studio. With the viewport
 * gone there was no input left, which is why /render-3d had nothing to work from and
 * renderCapability() honestly reported the studio as unavailable.
 *
 * So this does not resurrect the 3D project. It uses the parts the customer actually
 * picked - the real GPU, case, CPU, memory, storage and PSU names from OUR catalogue - and
 * sends those verified part names to Grok Imagine through the authenticated Studio image
 * gateway. No ComfyUI account or browser-side provider key is needed.
 *
 * WHY AN EXPLICIT PROMPT IS SENT
 * ------------------------------
 * aigen's /api/generate-media accepts a prompt or a reference image. It also has code that
 * composes a prompt from a parts list, but its input guard rejects that path when no prompt
 * is supplied, and that guard could not be deployed (the aigen image build is blocked by a
 * Docker Desktop build-context permission error). So the brief is composed HERE, on the
 * server, from the part names - and the parts list is still sent, so when the aigen fix
 * eventually lands the studio can refine it rather than ignore it.
 *
 * HONEST LIMITS, stated rather than hidden:
 *   - This is a GENERATED visual, not a photograph of the customer's actual machine. The
 *     caption and the UI must not imply otherwise.
 *   - It renders the specification. It is not a guarantee of how a given unit performs.
 *   - Generation produces an illustrative visual, not proof that every physical detail is
 *     exact. The caption and UI must not imply otherwise.
 */
class BuildPreviewController extends Controller
{
    /** Rendered previews, keyed by build hash, so the wait happens once. */
    private const CACHE_FILE = 'build-previews.json';

    /** How long a generated preview stays fresh before it is regenerated. */
    private const CACHE_TTL_HOURS = 24;

    public function preview(Request $request): JsonResponse
    {
        $ids = array_values(array_filter(array_map(
            'intval',
            (array) $request->input('component_ids', [])
        )));

        if (! $ids) {
            return response()->json([
                'error' => 'Pick at least one component before generating a preview.',
                'hint' => 'component_ids should be the ids of the parts currently in the build.',
            ], 422);
        }

        $parts = $this->partsFor($ids);
        if (! $parts) {
            return response()->json(['error' => 'None of those components are in the catalogue.'], 404);
        }

        // Cache key is the SET of parts, so changing any part gives a correct new render
        // and re-opening the same build costs nothing.
        $key = substr(hash('sha256', json_encode(array_map(
            fn ($k, $v) => "$k=$v",
            array_keys($parts),
            $parts
        ))), 0, 32);

        $cached = $this->cacheGet($key);
        if ($cached) {
            return response()->json(['preview' => $cached, 'cached' => true, 'parts' => $parts]);
        }

        $aigen = rtrim((string) config('aigenstudio.aigenUrl'), '/');
        $mediaToken = trim((string) config('aigenstudio.mediaToken'));
        if ($aigen === ''
            || preg_match('#^https?://(127\.0\.0\.1|localhost)#i', $aigen)
        ) {
            return response()->json([
                'error' => 'The render studio is not configured for this site, so a preview cannot be generated yet.',
                'configured' => false,
            ], 503);
        }
        if ($mediaToken === '') {
            return response()->json([
                'error' => 'Image generation authentication is not configured for this site yet.',
                'configured' => false,
            ], 503);
        }

        $prompt = $this->buildPrompt($parts);

        try {
            $res = Http::withToken($mediaToken)->timeout(180)->post($aigen.'/api/generate-media', [
                'prompt' => $prompt,
                'mode' => 'image',
                'provider' => 'grok',
                'noRef' => true,
                'parts' => $parts,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Could not reach the render studio: '.substr($e->getMessage(), 0, 160),
            ], 502);
        }

        if (! $res->successful()) {
            return response()->json([
                'error' => 'The render studio rejected the request (HTTP '.$res->status().').',
                'detail' => substr((string) $res->body(), 0, 300),
            ], 502);
        }

        $preview = $res->json('image_data_url');
        if (! is_string($preview)
            || ! preg_match('#^data:image/(?:jpeg|png|webp);base64,[A-Za-z0-9+/]+={0,2}$#', $preview)) {
            return response()->json([
                'error' => 'The render studio did not return a valid Grok image.',
                'detail' => substr((string) $res->body(), 0, 300),
            ], 502);
        }

        return response()->json([
            'preview' => $preview,
            'provider' => 'grok',
            'model' => 'grok-imagine-image-2.0',
            'note' => 'A generated visual of your selected specification. It is not a photograph of your actual machine.',
        ]);
    }

    public function status(Request $request, string $promptId): JsonResponse
    {
        try {
            $entry = $this->historyEntry($promptId);
        } catch (\Throwable $e) {
            Log::warning('[builder-preview] Could not read render status', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'failed', 'error' => 'Could not check the render studio status.'], 502);
        }

        if (! $entry) {
            return response()->json(['status' => 'processing']);
        }

        $status = $entry['status'] ?? [];
        $statusText = $status['status_str'] ?? '';
        if ($statusText === 'error') {
            return response()->json([
                'status' => 'failed',
                'error' => 'The render studio could not complete this preview.',
            ], 502);
        }

        if (($status['completed'] ?? false) !== true && $statusText !== 'success') {
            return response()->json(['status' => 'processing']);
        }

        if (! $this->imageInfo($entry)) {
            return response()->json([
                'status' => 'failed',
                'error' => 'The render studio completed without producing an image.',
            ], 502);
        }

        $imageUrl = route('builder.build.preview-image.image', ['promptId' => $promptId]);
        $key = $request->query('key');
        if (is_string($key) && preg_match('/^[a-f0-9]{32}$/', $key)) {
            $this->cachePut($key, $imageUrl);
        }

        return response()->json(['status' => 'completed', 'image_url' => $imageUrl]);
    }

    public function image(string $promptId)
    {
        try {
            $entry = $this->historyEntry($promptId);
            $image = $entry ? $this->imageInfo($entry) : null;
            if (! $image) {
                return response()->json(['error' => 'The preview image is not ready.'], 404);
            }

            $comfy = rtrim((string) config('aigenstudio.comfyUrl'), '/');
            $response = Http::withToken((string) config('aigenstudio.comfyToken'))
                ->timeout(20)->get($comfy.'/view', [
                'filename' => $image['filename'],
                'subfolder' => $image['subfolder'] ?? '',
                'type' => $image['type'] ?? 'output',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[builder-preview] Could not retrieve completed image', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Could not retrieve the completed preview image.'], 502);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'The render studio could not serve the completed preview image.'], 502);
        }

        $contentType = strtolower((string) $response->header('Content-Type', ''));
        if (! in_array($contentType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return response()->json(['error' => 'The render studio returned an unsupported image type.'], 502);
        }

        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function historyEntry(string $promptId): ?array
    {
        $comfy = rtrim((string) config('aigenstudio.comfyUrl'), '/');
        $response = Http::withToken((string) config('aigenstudio.comfyToken'))
            ->timeout(8)->get($comfy.'/history/'.rawurlencode($promptId));
        if (! $response->successful()) {
            throw new \RuntimeException('Render history request failed with HTTP '.$response->status().'.');
        }

        $entry = $response->json($promptId);

        return is_array($entry) ? $entry : null;
    }

    private function imageInfo(array $entry): ?array
    {
        foreach (($entry['outputs'] ?? []) as $output) {
            foreach (($output['images'] ?? []) as $image) {
                $filename = $image['filename'] ?? null;
                $type = $image['type'] ?? 'output';
                if (! is_string($filename) || $filename === '' || basename($filename) !== $filename
                    || ! in_array($type, ['output', 'temp'], true)) {
                    continue;
                }

                return [
                    'filename' => $filename,
                    'subfolder' => is_string($image['subfolder'] ?? null) ? $image['subfolder'] : '',
                    'type' => $type,
                ];
            }
        }

        return null;
    }

    /** Looks the real part names up from the catalogue - never invented here. */
    private function partsFor(array $ids): array
    {
        $rows = DB::table('components')
            ->join('categories', 'components.category_id', '=', 'categories.id')
            ->whereIn('components.id', $ids)
            ->where('components.active', true)
            ->get(['components.name', 'categories.slug']);

        $map = [
            'gpu' => 'gpu', 'cpu' => 'cpu', 'case' => 'case', 'motherboard' => 'motherboard',
            'ram' => 'ram', 'memory' => 'ram', 'storage' => 'storage', 'ssd' => 'storage',
            'psu' => 'psu', 'cooler' => 'cooler', 'case-fan' => 'case-fan',
        ];

        $parts = [];
        foreach ($rows as $r) {
            $key = $map[$r->slug] ?? $r->slug;
            // First match wins, so a duplicate category cannot overwrite a real pick.
            if (! isset($parts[$key]) && filled($r->name)) {
                $parts[$key] = (string) $r->name;
            }
        }

        return $parts;
    }

    /** The studio brief, composed from the real part names. UK English, GBP-safe, no claims. */
    private function buildPrompt(array $parts): string
    {
        $listed = collect($parts)->map(fn ($v, $k) => $k.': '.$v)->implode(', ');

        return 'Photograph the completed, fully assembled custom gaming PC as one finished product '
            .'inside its real case, in a three-quarter view from the glass side. The large clear '
            .'tempered-glass side panel must face the camera, with the whole interior brightly and '
            .'sharply visible. Show the selected motherboard installed in the case, the exact graphics '
            .'card seated horizontally in its PCIe slot, the CPU installed under its selected cooler, '
            .'the selected RAM sticks in the motherboard slots, storage installed, and the PSU fitted '
            .'in the lower shroud with neatly routed cables. Keep the glass transparent and reflections '
            .'subtle; do not use a solid side panel, hide the interior, show an empty case, or scatter '
            .'loose parts outside the finished PC. The selected component identities are: '.$listed.'. '
            .'Use a clean dark studio surface, soft product lighting and restrained red rim light. '
            .'Keep every visible component physically plausible and consistent with the selected parts. '
            .'No text, labels, logos, watermark or people.';
    }

    private function cachePath(): string
    {
        return storage_path('app/'.self::CACHE_FILE);
    }

    private function cacheGet(string $key): ?string
    {
        $file = $this->cachePath();
        if (! is_file($file)) {
            return null;
        }
        $all = json_decode((string) file_get_contents($file), true) ?: [];
        $row = $all[$key] ?? null;
        if (! $row || ! isset($row['at'])) {
            return null;
        }
        if (time() - (int) $row['at'] > self::CACHE_TTL_HOURS * 3600) {
            return null;
        }

        return $row['url'] ?? null;
    }

    private function cachePut(string $key, string $url): void
    {
        $file = $this->cachePath();
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }
        $all = json_decode((string) (is_file($file) ? file_get_contents($file) : '{}'), true) ?: [];
        $all[$key] = ['url' => $url, 'at' => time()];
        // Keep the cache small; these are convenience, not records.
        if (count($all) > 200) {
            $all = array_slice($all, -200, null, true);
        }
        file_put_contents($file, json_encode($all));
    }
}