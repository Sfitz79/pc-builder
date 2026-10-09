<?php
namespace Tests\Feature;

use App\Services\CompatibilityCheckerService;
use Tests\TestCase;

class CompatibilityCheckerPsuSpecsTest extends TestCase
{
    private function summaryForPsu(array $psu): array
    {
        return (new CompatibilityCheckerService())->generateSummary(['psu' => $psu]);
    }

    private function psu(array $specs): array
    {
        return [
            'name' => 'Corsair RM750x',
            'price' => 129.99,
            'wattage' => 750,
            'specs' => $specs,
        ];
    }

    public function test_psu_specs_read_the_sourced_efficiency_fields(): void
    {
        $specs = $this->summaryForPsu($this->psu([
            'efficiency_rating' => '80+ Platinum',
            'modular' => 'Full Modular',
            'form_factor' => 'ATX',
        ]))['components']['psu']['specs'];

        $this->assertSame('80+ Platinum', $specs['Efficiency']);
        $this->assertSame('Full Modular', $specs['Modular']);
        $this->assertSame('ATX', $specs['Form Factor']);
        $this->assertSame('750W', $specs['Wattage']);
    }

    public function test_psu_specs_say_na_when_nothing_is_sourced(): void
    {
        $specs = $this->summaryForPsu($this->psu([]))['components']['psu']['specs'];

        $this->assertSame('N/A', $specs['Efficiency']);
        $this->assertSame('N/A', $specs['Modular']);
        $this->assertSame('N/A', $specs['Form Factor']);
    }

    public function test_psu_specs_present_efficiency_without_requiring_modular_or_form_factor(): void
    {
        $specs = $this->summaryForPsu($this->psu([
            'efficiency_rating' => '80+ Gold',
        ]))['components']['psu']['specs'];

        $this->assertSame('80+ Gold', $specs['Efficiency']);
        $this->assertSame('N/A', $specs['Modular']);
        $this->assertSame('N/A', $specs['Form Factor']);
    }
}