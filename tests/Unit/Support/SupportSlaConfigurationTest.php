<?php

namespace Tests\Unit\Support;

use App\Models\SystemSetting;
use App\Support\SupportSlaConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportSlaConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_default_configuration_when_no_overrides_are_set(): void
    {
        $sla = SupportSlaConfiguration::all();

        $this->assertSame(
            config('support.sla.priority_escalations.low.after'),
            $sla['priority_escalations']['low']['after']
        );

        $this->assertSame(
            config('support.sla.reassign_after.high'),
            $sla['reassign_after']['high']
        );
    }

    public function test_overrides_configuration_with_system_settings(): void
    {
        SystemSetting::set('support.sla', [
            'priority_escalations' => [
                'low' => [
                    'after' => '6 hours',
                    'to' => 'high',
                ],
            ],
            'reassign_after' => [
                'medium' => '12 hours',
            ],
        ]);

        $sla = SupportSlaConfiguration::all();

        $this->assertSame('6 hours', $sla['priority_escalations']['low']['after']);
        $this->assertSame('high', $sla['priority_escalations']['low']['to']);
        $this->assertSame('12 hours', $sla['reassign_after']['medium']);
        $this->assertSame(
            config('support.sla.reassign_after.high'),
            $sla['reassign_after']['high']
        );
    }
}
