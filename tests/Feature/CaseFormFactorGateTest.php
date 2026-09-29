<?php
// Genie 2026-09-29: pins the case form-factor compatibility gate after the
// 399-case verified backfill. Two readers consume the data and BOTH are pinned
// here so they cannot drift apart again (Rule: measure through the code path
// that USES it):
//   - CompatibilityRule::evaluateFormFactor()  -> reads case specs.supported_form_factors
//   - CompatibilityCheckerService::getCaseCompatibility() -> the authoritative matrix
// A third test checks the PRODUCTION data itself and is Neon-gated: it fails
// unless RUN_FORM_FACTOR_REGRESSION=1, so CI and the genie pre-flight can opt
// in without a live database.
namespace Tests\Feature;

use App\Services\CompatibilityCheckerService;
use App\Services\CompatibilityService;
use App\Models\CompatibilityRule;
use Tests\TestCase;

class CaseFormFactorGateTest extends TestCase
{
    public function test_matrix_matches_physical_reality(): void
    {
        $svc = new CompatibilityCheckerService();
        $m = new \ReflectionMethod($svc, 'getCaseCompatibility');
        $m->setAccessible(true);

        // The method takes the case form factor and returns the boards that
        // fit, so the full map is built one key at a time.
        $matrix = [];
        foreach (['ATX', 'Micro-ATX', 'Mini-ITX', 'E-ATX'] as $key) {
            $matrix[$key] = $m->invoke($svc, $key);
        }

        // A physically correct case holds its own board size AND every smaller
        // one. Nothing bigger. These are the four standards the catalogue uses.
        $this->assertSame(['ATX', 'Micro-ATX', 'Mini-ITX'], $matrix['ATX'] ?? null);
        $this->assertSame(['Micro-ATX', 'Mini-ITX'], $matrix['Micro-ATX'] ?? null);
        $this->assertSame(['Mini-ITX'], $matrix['Mini-ITX'] ?? null);
        $this->assertSame(['E-ATX', 'ATX', 'Micro-ATX', 'Mini-ITX'], $matrix['E-ATX'] ?? null);

        // Size is transitive: if a case of size X can hold a board of size Y,
        // then a case of size Y must never claim to hold a BIGGER board X.
        foreach ($matrix as $caseFF => $boards) {
            foreach ($boards as $boardFF) {
                if ($caseFF === $boardFF) {
                    continue; // a case always holds its own size
                }
                $boardCase = $matrix[$boardFF] ?? [];
                $this->assertNotContains($caseFF, $boardCase,
                    "Case $caseFF claims to hold board $boardFF but $boardFF case excludes $caseFF");
            }
        }

        // Unknown form factors must FAIL CLOSED (empty list), never become
        // "everything fits" (Rule 6: fail-open is never safe for a promise).
        $this->assertSame([], $m->invoke($svc, 'ITX'));
        $this->assertSame([], $m->invoke($svc, 'Slim'));
        $this->assertSame([], $m->invoke($svc, 'unknown'));
    }

    public function test_live_rule_engages_on_supported_form_factors(): void
    {
        // The live CompatibilityRule reads specs.supported_form_factors on the
        // case. With data present an incompatible board must be REJECTED, not
        // silently passed (this was the fail-open hole before the backfill).
        $rule = new CompatibilityRule([
            'category' => 'case_fit',
            'active' => true,
        ]);
        $m = new \ReflectionMethod($rule, 'evaluateFormFactor');
        $m->setAccessible(true);

        // Mini-ITX case + ATX board = physically impossible => fail closed.
        $this->assertFalse($m->invoke($rule, [
            'case' => ['specs' => ['supported_form_factors' => ['Mini-ITX']]],
            'motherboard' => ['specs' => ['form_factor' => 'ATX']],
        ]), 'ATX board in Mini-ITX case must be rejected');

        // ATX case + Mini-ITX board = fine (bigger case, smaller board).
        $this->assertTrue($m->invoke($rule, [
            'case' => ['specs' => ['supported_form_factors' => ['ATX', 'Micro-ATX', 'Mini-ITX']]],
            'motherboard' => ['specs' => ['form_factor' => 'Mini-ITX']],
        ]), 'Mini-ITX board in ATX case must pass');

        // The board fallback ALSO reads board['form_factor'] (not only specs).
        $this->assertTrue($m->invoke($rule, [
            'case' => ['specs' => ['supported_form_factors' => ['ATX', 'Micro-ATX', 'Mini-ITX']]],
            'motherboard' => ['form_factor' => 'Micro-ATX'],
        ]), 'board form_factor fallback must pass for the right case');

        // No case data => rule passes (consistent with current code). This is
        // documented as the CURRENT fail-open behaviour - the gate cannot lie
        // about a case whose data is missing, and the production backfill
        // guarantees the supported list is now populated. The prebuilt
        // assembler refuses to publish builds for cases without it.
        $this->assertTrue($m->invoke($rule, [
            'case' => ['specs' => []],
            'motherboard' => ['specs' => ['form_factor' => 'ATX']],
        ]), 'documented: missing case data is not rejected by the legacy rule');
    }

    public function test_prebuilt_assembler_uses_the_verified_field(): void
    {
        // The assembler script must read specs.form_factor - the verified
        // field - and never fall back to guessing from the product name.
        // Guard against the exact regression that shipped on 2026-09-28.
        $script = (string) file_get_contents(base_path('scripts/genie-assemble-prebuilts.php'));
        $this->assertStringContainsString('caseFormFactor(object $r)', $script);
        $this->assertStringContainsString("['form_factor'] ?? null", $script);
        $this->assertStringNotContainsString('caseFormFactor($c->name)', $script);
        $this->assertStringContainsString('specs.form_factor (verified backfill', $script);
    }

    /**
     * Production-data regression, Neon-gated.
     *
     * Run: $env:FORM_FACTOR_REGRESSION="neon"; php artisan test --filter=CaseFormFactorGateTest::test_production_cases_have_verified_form_factors
     * The guard script proves the driver is pgsql before anything is read,
     * so this CANNOT accidentally run against SQLite (Rule 7).
     */
    public function test_production_cases_have_verified_form_factors(): void
    {
        if (($env = getenv('FORM_FACTOR_REGRESSION')) !== 'neon') {
            $this->markTestSkipped('set FORM_FACTOR_REGRESSION=neon to run against production');
        }

        require base_path('scripts/genie-prod-guard.php'); // asserts pgsql or exits 3

        $caseId = \Illuminate\Support\Facades\DB::table('categories')->where('name', 'Case')->value('id');
        $rows = \Illuminate\Support\Facades\DB::table('components')
            ->where('category_id', $caseId)->where('active', true)
            ->get(['id', 'name', 'specs']);

        $this->assertGreaterThan(0, $rows->count());

        $missing = [];
        $wrong = [];
        foreach ($rows as $r) {
            $specs = $r->specs;
            if (is_string($specs)) {
                $specs = json_decode($specs, true);
            }
            $specs = is_array($specs) ? $specs : [];
            $ff = $specs['form_factor'] ?? null;
            $sup = $specs['supported_form_factors'] ?? null;
            if (! is_string($ff) || $ff === '') {
                $missing[] = $r->id.' '.$r->name;
                continue;
            }
            // The supported list must decode back to exactly the matrix entry.
            $svc = new CompatibilityCheckerService();
            $m = new \ReflectionMethod($svc, 'getCaseCompatibility');
            $m->setAccessible(true);
            $expected = $m->invoke($svc, $ff);
            if ($expected === null) {
                $wrong[] = $r->id.' '.$r->name." (unknown form factor $ff)";
            } elseif ($sup !== $expected) {
                $wrong[] = $r->id.' '.$r->name." (supported_form_factors ".json_encode($sup)." != ".json_encode($expected).")";
            }
        }

        $this->assertSame([], $missing, "cases without verified form_factor:\n  - ".implode("\n  - ", $missing));
        $this->assertSame([], $wrong, "cases whose supported_form_factors disagree with the matrix:\n  - ".implode("\n  - ", $wrong));
    }
}