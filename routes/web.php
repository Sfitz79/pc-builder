<?php

use App\Http\Controllers\BuilderController;
use App\Http\Controllers\BuildController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PrebuiltController;
use App\Http\Controllers\FeaturedController;
use App\Http\Controllers\SoftwareController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PCTG Builder Routes
|--------------------------------------------------------------------------
|
| The builder is fully public — visitors can build, generate and save
| without an account. Logged-in users additionally see their saved builds
| tied to their account; guests get session-scoped builds instead.
|
*/

// Jetstream default post-login landing (Fortify redirects authenticated users here).
Route::get('/dashboard', function () {
    return redirect()->route('builder');
})->name('dashboard');

// The builder and its JSON endpoints are public.
Route::get(
    '/builder',
    function () {
        return view('builder.dashboard');
    }
)->name('builder');

// Builder sub-pages.
Route::prefix('builder')->name('builder.')->group(function () {
    Route::get('/ai', [BuilderController::class, 'generate'])->name('generate');
    Route::get('/manual', [BuilderController::class, 'manual'])->name('manual');
    Route::get('/checkout', [BuilderController::class, 'checkout'])->name('checkout');
    Route::get('/checkout/payment', [BuilderController::class, 'payment'])->name('checkout.payment');

    // Alpine JSON endpoints (feed the builder store from the database).
    Route::get('/catalog', [BuilderController::class, 'catalog'])->name('catalog');
    Route::get('/fps', [BuilderController::class, 'fps'])->name('fps');
    // Measured, achievable price bands. The budget slider and the below-minimum
    // clamp both read this, so the smallest budget a customer can pick is
    // always a budget that genuinely produces a build.
    Route::get('/bands', [BuilderController::class, 'bands'])->name('bands');
    Route::post('/ai', [BuilderController::class, 'ai'])->name('ai');
    Route::post('/validate', [BuilderController::class, 'validate'])->name('validate');
    Route::post('/price', [BuilderController::class, 'price'])->name('price');

    // 3D build viewport: same-origin part-image proxy (textures) + the
    // render-to-Storefront bridge (3D geometry → aigen → photoreal render).
    Route::get('/part-image/{component}', [BuilderController::class, 'partImage'])->name('part-image');
    // Procedural 3D geometry for the current selection. Cached by signature,
    // and answerless-but-safe: it returns ok:false rather than 500 so the
    // viewport falls back to the legacy primitive scene instead of breaking.
    Route::post('/mesh-spec', [BuilderController::class, 'meshSpec'])->name('mesh-spec');
    Route::post('/render-3d', [BuilderController::class, 'render3d'])->name('render-3d');
    // Whether the render bridge is usable, so the button is only shown when it
    // can actually work. See BuilderController::renderCapability().
    Route::get('/render-capability', [BuilderController::class, 'renderCapability'])
        ->name('render-capability');

    // Saved build lifecycle (guest + authenticated owners).
    Route::get('/builds', [BuildController::class, 'index'])->name('builds');
    Route::post('/builds', [BuildController::class, 'store'])->name('builds.store');
    Route::post('/builds/generate', [BuildController::class, 'generate'])->name('builds.generate');
    Route::patch('/builds/{build}', [BuildController::class, 'update'])->name('builds.update');
    Route::delete('/builds/{build}', [BuildController::class, 'destroy'])->name('builds.destroy');

    // Orders / checkout — backed by PayPal.
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/paypal', [OrderController::class, 'paypal'])->name('orders.paypal');
    Route::get('/orders/{order}/paypal/return', [OrderController::class, 'paypalReturn'])->name('orders.paypal.return');
    Route::post('/orders/{order}/paypal/capture', [OrderController::class, 'capture'])->name('orders.paypal.capture');
    Route::get('/orders/{order}/confirmed', [OrderController::class, 'confirmed'])->name('orders.confirmed');
    Route::get('/builds/{build}/mockup.png', [OrderController::class, 'mockup'])->name('builds.mockup');
});

// Public shareable build URLs (no auth required).
Route::get('/build/{shareSlug}', [BuildController::class, 'show'])
    ->where('shareSlug', '[a-zA-Z0-9]+')
    ->name('build.show');

// Public marketing pages use the shared app layout.
Route::get('/', function () {
    return view('welcome');
})->name('home');

// Software store (license keys fulfilled via Metenzi).
Route::get('/software', [SoftwareController::class, 'index'])->name('software');

Route::prefix('software')->name('software.')->group(function () {
    Route::post('/purchases', [SoftwareController::class, 'purchase'])->name('purchases.store');
    Route::get('/purchases/{purchase}/payment', [SoftwareController::class, 'payment'])->name('purchases.payment');
    Route::post('/purchases/{purchase}/paypal', [SoftwareController::class, 'paypal'])->name('purchases.paypal');
    Route::get('/purchases/{purchase}/paypal/return', [SoftwareController::class, 'paypalReturn'])->name('purchases.paypal.return');
    Route::post('/purchases/{purchase}/paypal/capture', [SoftwareController::class, 'capture'])->name('purchases.paypal.capture');
    Route::post('/purchases/{purchase}/fulfil', [SoftwareController::class, 'retryFulfilment'])->name('purchases.fulfil');
    Route::get('/purchases/{purchase}/confirmed', [SoftwareController::class, 'confirmed'])->name('purchases.confirmed');
});

// Metenzi webhook receiver (CSRF-exempt, HMAC-verified).
Route::post('/webhooks/metenzi', [SoftwareController::class, 'webhook'])->name('metenzi.webhook');


// Public info pages linked from the primary navigation.
Route::view('/components', 'info-page', [
    'title' => 'Components',
    'subtitle' => 'Every part in a PCTG build is hand-picked for compatibility, real-world performance and value for money.',
    'items' => [
        ['icon' => 'cpu', 'title' => 'Processors', 'body' => 'AMD Ryzen CPUs from value six-core chips to the 9800X3D gaming flagship — always paired with a socket-matched board.'],
        ['icon' => 'gpu', 'title' => 'Graphics Cards', 'body' => 'NVIDIA GeForce RTX 40 and 50 series. Chosen for the games you play, not just the spec sheet.'],
        ['icon' => 'memory-stick', 'title' => 'Memory & Storage', 'body' => 'Fast DDR5 kits sized for your workload, plus Gen4 NVMe drives that keep load screens short.'],
        ['icon' => 'computer', 'title' => 'Cases & Cooling', 'body' => 'Airflow-first cases with quality cooling, so performance holds up under long sessions.'],
        ['icon' => 'power', 'title' => 'Power Supplies', 'body' => '80+ rated units with headroom for your GPU — wattage is checked on every single build.'],
        ['icon' => 'shield-check', 'title' => 'Compatibility Verified', 'body' => 'Socket, clearance, wattage and BIOS checks run on every combination before you order.'],
    ],
])->name('components');

// Pre-built configurator listings (live catalogue prices with an honest
// price-fluctuation disclaimer, per Boss decision 2026-09-29).
Route::get('/prebuilts', [PrebuiltController::class, 'index'])->name('prebuilts');

// Featured systems range: three closed axes (usage, resolution, tier).
Route::get('/featured', [FeaturedController::class, 'index'])->name('featured');
Route::get('/featured/{slug}/apply', [FeaturedController::class, 'apply'])->name('featured.apply');
Route::get('/builder/preset/{slug}', [PrebuiltController::class, 'preset'])->name('prebuilts.preset');

/*
 * PCTG Business — the professional sub-brand.
 *
 * A separate blade rather than a themed variant of the gaming landing page,
 * because it is a different product aimed at a different buyer: workstations,
 * studio/render systems and enterprise servers instead of gaming scenarios,
 * with its own royal-blue palette and its own sub-brand lockup.
 *
 * Stated as a view, not a controller, because it carries no dynamic data and
 * quotes nothing. It deliberately publishes no prices and no benchmark figures
 * - business buyers want a spec conversation, and inventing numbers here would
 * repeat the GBP 1,096 headline failure in a more expensive context.
 */
Route::view('/business', 'business.index')->name('business');
Route::redirect('/pctg-business', '/business');

Route::view('/support', 'info-page', [
    'title' => 'Support',
    'subtitle' => 'From ordering to upgrades, the PCTG team is behind every build.',
    'items' => [
        ['icon' => 'headset', 'title' => 'Order Support', 'body' => 'Questions about a build, delivery times or finance? Our UK team is on hand to help.'],
        ['icon' => 'activity', 'title' => 'Burn Test Reports', 'body' => 'Every system ships with a burn test report showing temperatures, stability and a clean bill of health.'],
        ['icon' => 'box', 'title' => 'Delivery', 'body' => 'Systems are securely packed and shipped to arrive ready to plug in and play.'],
        ['icon' => 'shield-check', 'title' => 'Warranty', 'body' => 'Warranty coverage included on every build, with fast turnaround on any hardware issue.'],
        ['icon' => 'refresh-cw', 'title' => 'Upgrades', 'body' => 'Want to upgrade later? Compatible paths are noted on every build so you can plan ahead.'],
        ['icon' => 'wallet', 'title' => 'Finance Help', 'body' => 'Guidance on payment plans, early settlement and credit options whenever you need it.'],
    ],
])->name('support');

Route::view('/privacy', 'info-page', [
    'title' => 'Privacy Policy',
    'subtitle' => 'How PCTG Builder collects, uses and protects your personal data.',
    'items' => [
        ['icon' => 'layers', 'title' => 'Data We Collect', 'body' => 'Account details, saved builds and order information needed to run the builder and process orders.'],
        ['icon' => 'activity', 'title' => 'How We Use It', 'body' => 'To power the builder, save your work, fulfil orders and improve the site. We never sell your data.'],
        ['icon' => 'info', 'title' => 'Cookies', 'body' => 'Essential cookies keep you logged in and the builder working; analytics cookies are optional.'],
        ['icon' => 'shield-check', 'title' => 'Data Security', 'body' => 'Your data is stored securely with access limited to the staff that need it.'],
        ['icon' => 'check-circle', 'title' => 'Your Rights', 'body' => 'You can export or delete your saved builds and account data at any time.'],
        ['icon' => 'headset', 'title' => 'Contact', 'body' => 'Questions about this policy? Reach out via our support page and we will respond promptly.'],
    ],
])->name('privacy');

Route::view('/terms', 'info-page', [
    'title' => 'Terms of Service',
    'subtitle' => 'The terms that apply when you use PCTG Builder and order a custom PC.',
    'items' => [
        ['icon' => 'credit-card', 'title' => 'Orders & Payment', 'body' => 'Builds are priced at checkout including build service and delivery. Payment is required before dispatch.'],
        ['icon' => 'shield-check', 'title' => 'Warranty', 'body' => 'Every build is covered by our standard warranty. See Support for the full terms.'],
        ['icon' => 'refresh-cw', 'title' => 'Returns', 'body' => 'Unwanted builds can be returned within the cooling-off period, subject to component condition.'],
        ['icon' => 'cpu', 'title' => 'Build Accuracy', 'body' => 'Parts shown are subject to availability; equivalent or better substitutions are made at our discretion.'],
        ['icon' => 'gauge', 'title' => 'FPS Estimates', 'body' => 'Performance estimates are indicative and vary with drivers, thermals and background workloads.'],
        ['icon' => 'wallet', 'title' => 'Governing Law', 'body' => 'These terms are governed by the laws of England and Wales.'],
        ['icon' => 'layers', 'title' => 'Software Store', 'body' => 'License keys are delivered digitally after payment clears and are not refundable once shown. Windows and Office keys require system and region compatibility.'],
    ],
])->name('terms');

// SEO budget guide cluster (public, static Blade pages).
Route::view('/faq', 'faq')->name('faq');

// Buyer-intent guide cluster (public, static Blade pages).
Route::view('/guides/best-uk-custom-gaming-pc-builder', 'seo.guides.best-uk-custom-gaming-pc-builder');
Route::view('/guides/custom-gaming-pc-under-1000-uk', 'seo.guides.custom-gaming-pc-under-1000-uk');
Route::view('/guides/prebuilt-gaming-pc-under-800-uk', 'seo.guides.prebuilt-gaming-pc-under-800-uk');
Route::view('/guides/best-value-gaming-pc-builder-uk', 'seo.guides.best-value-gaming-pc-builder-uk');
Route::view('/guides/gaming-pc-900-1440p-uk', 'seo.guides.gaming-pc-900-1440p-uk');
Route::view('/guides/custom-gaming-pc-warranty-support-uk', 'seo.guides.custom-gaming-pc-warranty-support-uk');

Route::view('/best-gaming-pc-under-1000', 'seo.budget.1000');
Route::view('/best-gaming-pc-under-1500', 'seo.budget.1500');
Route::view('/best-gaming-pc-under-2000', 'seo.budget.2000');
Route::view('/best-gaming-pc-under-2500', 'seo.budget.2500');
Route::view('/best-gaming-pc-under-3000', 'seo.budget.3000');

// SEO use-case / game guide cluster (public, static Blade pages).
Route::view('/best-pc-for-fortnite', 'seo.game.fortnite');
Route::view('/best-pc-for-warzone', 'seo.game.warzone');
Route::view('/best-pc-for-streaming', 'seo.use.streaming');

/*
|--------------------------------------------------------------------------
| API Routes (web middleware)
|--------------------------------------------------------------------------
|
| These API endpoints live under the web middleware group so they work on
| the Vercel serverless runtime without requiring the separate api
| middleware group.  The routes are CSRF-exempt so external POST callers
| (the Genie auto-reply engine, AI Builder, etc.) can hit them without
| a session token.
|
*/

use App\Http\Controllers\Api\BuildRecommendationController;
use App\Http\Controllers\Api\PriceComparisonController;

use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

Route::prefix('api')->group(function () {
    Route::post('/build/recommend', [BuildRecommendationController::class, 'recommend']);
    Route::post('/build/compare-prices', [PriceComparisonController::class, 'comparePrices']);
    Route::get('/build/search/buywhere', [PriceComparisonController::class, 'searchBuyWhere']);
    Route::post('/build/check-compatibility', [PriceComparisonController::class, 'checkCompatibility']);
    Route::post('/build/summary', [PriceComparisonController::class, 'getBuildSummary']);
});

// Search-engine plumbing for the guide cluster.
Route::get('/llms.txt', function () {
    return response(file_get_contents(public_path('llms.txt')), 200, [
        'Content-Type' => 'text/plain; charset=utf-8',
    ]);
})->name('llms.txt');

Route::get('/robots.txt', function () {
    return response(
        "User-agent: *\nAllow: /\nDisallow: /404\n\n" .
        "User-agent: GPTBot\nAllow: /\n\n" .
        "User-agent: OAI-SearchBot\nAllow: /\n\n" .
        "User-agent: ChatGPT-User\nAllow: /\n\n" .
        "User-agent: ClaudeBot\nAllow: /\n\n" .
        "User-agent: PerplexityBot\nAllow: /\n\n" .
        "User-agent: Google-Extended\nAllow: /\n\n" .
        "User-agent: CCBot\nAllow: /\n\n" .
        "Sitemap: " . secure_url('/sitemap.xml') . "\n" .
        "Sitemap: " . secure_url('/llms.txt') . "\n",
        200,
        ['Content-Type' => 'text/plain']
    );
});

Route::get('/sitemap.xml', function () {
    $entries = [
        // Core pages.
        ['/', 1.0, Url::CHANGE_FREQUENCY_WEEKLY],
        ['/builder', 0.9, Url::CHANGE_FREQUENCY_WEEKLY],
        ['/builder/ai', 0.8, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/builder/manual', 0.8, Url::CHANGE_FREQUENCY_MONTHLY],

        // Catalogue / public guides.
        ['/components', 0.8, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/prebuilts', 0.8, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/software', 0.8, Url::CHANGE_FREQUENCY_MONTHLY],

        // SEO budget guide cluster.
        ['/best-gaming-pc-under-1000', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/best-gaming-pc-under-1500', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/best-gaming-pc-under-2000', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/best-gaming-pc-under-2500', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/best-gaming-pc-under-3000', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],

        // SEO use-case / game guide cluster.
        ['/best-pc-for-fortnite', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/best-pc-for-warzone', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/best-pc-for-streaming', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],

        // Buyer-intent guide cluster.
        ['/guides/best-uk-custom-gaming-pc-builder', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/guides/custom-gaming-pc-under-1000-uk', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/guides/prebuilt-gaming-pc-under-800-uk', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/guides/best-value-gaming-pc-builder-uk', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/guides/gaming-pc-900-1440p-uk', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],
        ['/guides/custom-gaming-pc-warranty-support-uk', 0.7, Url::CHANGE_FREQUENCY_MONTHLY],

        // FAQ.
        ['/faq', 0.6, Url::CHANGE_FREQUENCY_MONTHLY],

        // Company / support pages.
        ['/support', 0.3, Url::CHANGE_FREQUENCY_YEARLY],
        ['/privacy', 0.2, Url::CHANGE_FREQUENCY_YEARLY],
        ['/terms', 0.2, Url::CHANGE_FREQUENCY_YEARLY],
        ['/admin', 0.1, Url::CHANGE_FREQUENCY_YEARLY],
    ];

    $sitemap = Sitemap::create();
    $lastmod = now();

    foreach ($entries as [$path, $priority, $changeFrequency]) {
        $sitemap->add(
            Url::create(secure_url($path))
                ->setPriority($priority)
                ->setChangeFrequency($changeFrequency)
                ->setLastModificationDate($lastmod)
        );
    }

    return $sitemap->toResponse(request());
})->name('sitemap');
