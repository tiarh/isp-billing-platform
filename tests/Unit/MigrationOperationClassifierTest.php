<?php

namespace Tests\Unit;

use App\Services\MigrationOperationClassifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('blocking-psb')]
class MigrationOperationClassifierTest extends TestCase
{
    private MigrationOperationClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new MigrationOperationClassifier;
    }

    public function test_target_router_change_is_a_normal_migration(): void
    {
        $result = $this->classifier->classify($this->input([
            'current_router_id' => 10,
            'target_router_id' => 20,
            'new_sn' => 'FHTTNEW001',
            'current_sn' => 'FHTTOLD001',
        ]));

        $this->assertSame(MigrationOperationClassifier::MIGRATION, $result['operation_type']);
        $this->assertSame('TARGET_ROUTER_CHANGED', $result['reason_code']);
        $this->assertFalse($result['requires_confirmation']);
    }

    public function test_same_target_new_sn_with_replacement_reason_requires_confirmation(): void
    {
        $result = $this->classifier->classify($this->input([
            'new_sn' => 'FHTTNEW001',
            'replacement_reason_present' => true,
        ]));

        $this->assertSame(MigrationOperationClassifier::HARDWARE_REPLACEMENT, $result['operation_type']);
        $this->assertSame('SAME_TARGET_NEW_SN_DEVICE_REPLACED', $result['reason_code']);
        $this->assertTrue($result['requires_confirmation']);
    }

    public function test_same_target_new_sn_without_reason_is_pending_confirmation(): void
    {
        $result = $this->classifier->classify($this->input([
            'new_sn' => 'FHTTNEW001',
        ]));

        $this->assertSame(MigrationOperationClassifier::PENDING_TECHNICIAN_CONFIRMATION, $result['operation_type']);
        $this->assertSame('SAME_TARGET_NEW_SN_NO_CONFIRMATION', $result['reason_code']);
        $this->assertTrue($result['requires_confirmation']);
    }

    public function test_same_target_same_sn_is_duplicate(): void
    {
        $result = $this->classifier->classify($this->input([
            'new_sn' => 'FHTTOLD001',
        ]));

        $this->assertSame(MigrationOperationClassifier::DUPLICATE_MIGRATION, $result['operation_type']);
        $this->assertSame('SAME_TARGET_SAME_SN_ALREADY_DONE', $result['reason_code']);
    }

    public function test_already_processed_new_sn_is_duplicate(): void
    {
        $result = $this->classifier->classify($this->input([
            'new_sn' => 'FHTTNEW001',
            'already_processed' => true,
        ]));

        $this->assertSame(MigrationOperationClassifier::DUPLICATE_MIGRATION, $result['operation_type']);
    }

    public function test_old_router_mismatch_is_only_a_warning_when_identity_is_valid(): void
    {
        $result = $this->classifier->classify($this->input([
            'old_router_id' => 99,
            'new_sn' => 'FHTTNEW001',
            'replacement_reason_present' => true,
        ]));

        $this->assertSame(MigrationOperationClassifier::HARDWARE_REPLACEMENT, $result['operation_type']);
        $this->assertContains('OLD_ROUTER_MISMATCH_ONLY', $result['warnings']);
    }

    public function test_pppoe_case_mismatch_is_a_conflict(): void
    {
        $result = $this->classifier->classify($this->input([
            'pppoe_user' => 'customer_rt1',
            'customer_pppoe_user' => 'CUSTOMER_RT1',
            'new_sn' => 'FHTTNEW001',
        ]));

        $this->assertSame(MigrationOperationClassifier::CONFLICT, $result['operation_type']);
        $this->assertSame('PPPOE_CONFLICT', $result['reason_code']);
    }

    public function test_same_identity_has_stable_operation_reference(): void
    {
        $input = $this->input([
            'new_sn' => 'FHTTNEW001',
            'replacement_reason_present' => true,
        ]);

        $first = $this->classifier->classify($input);
        $second = $this->classifier->classify($input);

        $this->assertSame($first['operation_key'], $second['operation_key']);
        $this->assertSame($first['operation_reference'], $second['operation_reference']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => 123,
            'current_router_id' => 10,
            'target_router_id' => 10,
            'old_router_id' => 10,
            'pppoe_user' => 'CUSTOMER_RT1',
            'customer_pppoe_user' => 'CUSTOMER_RT1',
            'current_sn' => 'FHTTOLD001',
            'new_sn' => 'FHTTNEW001',
            'already_processed' => false,
            'replacement_reason_present' => false,
            'technician_confirmed' => false,
        ], $overrides);
    }
}
