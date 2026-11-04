<?php

namespace Tests\Concerns;

use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use Mockery;

/**
 * Binds a stub CustomerLifecycleGuard whose per-ref ONU delete verdict and/or
 * customer-level verdict (canTerminate/canArchive) are fixed by the test. Tests
 * of the ONU delete mechanics (not of the guard itself) use
 * allowLifecycleGuard(); guard behaviour has its own tests. The two kinds of
 * stub compose: a test may call allowLifecycleGuard() and
 * allowCustomerLifecycleGuard() in any order and both stay in force.
 */
trait StubsLifecycleGuard
{
    private ?GuardDecision $stubbedOnuRefDecision = null;

    private ?GuardDecision $stubbedCustomerDecision = null;

    protected function allowLifecycleGuard(): void
    {
        $this->stubLifecycleGuard(GuardDecision::fromSignals('delete_onu_case', [
            GuardSignal::clear(GuardSignal::ONU, 'onu_ref_offline', ''),
        ]));
    }

    protected function stubLifecycleGuard(GuardDecision $decision): void
    {
        $this->stubbedOnuRefDecision = $decision;
        $this->bindStubbedLifecycleGuard();
    }

    /**
     * Customer-level verdict (canTerminate/canArchive) fixed by the test; the
     * ONU-ref delete verdict (unless stubbed too) and authorizeOverride() stay real/partial.
     */
    protected function stubCustomerLifecycleGuard(GuardDecision $decision): void
    {
        $this->stubbedCustomerDecision = $decision;
        $this->bindStubbedLifecycleGuard();
    }

    protected function allowCustomerLifecycleGuard(): void
    {
        $this->stubCustomerLifecycleGuard(GuardDecision::fromSignals('terminate', [
            GuardSignal::clear(GuardSignal::PPPOE, 'pppoe_offline_fresh', ''),
        ]));
    }

    protected function denyLifecycleGuard(string $message = 'Sesi PPPoE masih aktif (R1, dicek 2 menit lalu).'): void
    {
        $this->stubLifecycleGuard(GuardDecision::fromSignals('delete_onu_case', [
            GuardSignal::inUse(GuardSignal::PPPOE, 'pppoe_in_use', $message),
        ]));
    }

    /** Customer-level deny (canTerminate/canArchive), e.g. PPPoE still in use. */
    protected function denyCustomerLifecycleGuard(string $message = 'Sesi PPPoE masih aktif (R1, dicek 2 menit lalu).'): void
    {
        $this->stubCustomerLifecycleGuard(GuardDecision::fromSignals('terminate', [
            GuardSignal::inUse(GuardSignal::PPPOE, 'pppoe_in_use', $message),
        ]));
    }

    private function bindStubbedLifecycleGuard(): void
    {
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        if ($this->stubbedOnuRefDecision !== null) {
            $guard->shouldReceive('canDeleteOnuRef')->andReturn($this->stubbedOnuRefDecision);
        }
        if ($this->stubbedCustomerDecision !== null) {
            $guard->shouldReceive('canTerminate')->andReturn($this->stubbedCustomerDecision);
            $guard->shouldReceive('canArchive')->andReturn($this->stubbedCustomerDecision);
        }
        $this->app->instance(CustomerLifecycleGuard::class, $guard);
    }
}
