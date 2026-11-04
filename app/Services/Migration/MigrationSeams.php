<?php

namespace App\Services\Migration;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\Router;
use App\Models\User;
use App\Services\CustomerMigrationMessageParser;
use App\Services\GuidedMigrationLookupService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MigrationOperationClassifier;
use App\Services\ProductionControlPlaneService;
use App\Services\PsbOrchestratorService;
use Closure;

/**
 * Late-bound access from the migration collaborators to dependencies the facade owns.
 *
 * Each field is a closure created in CustomerMigrationService that reads the dependency
 * at call time, so a test that swaps it on the facade (or builds the facade without its
 * constructor) is honoured. A collaborator never holds the dependency itself.
 */
final readonly class MigrationSeams
{
    /**
     * @param  Closure(): PsbOrchestratorService  $psb
     * @param  Closure(): CustomerMigrationMessageParser  $parser
     * @param  Closure(): ProductionControlPlaneService  $controlPlane
     * @param  Closure(): HuaweiOltOnlyProvisioningService  $huaweiOltOnly
     * @param  Closure(): MigrationOperationClassifier  $operationClassifier
     * @param  Closure(CustomerMigration): ?Customer  $resolveCustomer
     * @param  Closure(Customer, Router): array<string, mixed>  $verifyExistingPppoeSecret
     * @param  Closure(Router, string): array<string, mixed>  $verifyTargetMigrationProfile
     * @param  Closure(): array<string, bool>  $huaweiOltOnlyWriteFlags
     * @param  Closure(int): array<int, array<string, mixed>>  $uncfgRows
     * @param  Closure(CustomerMigration, Olt, array<int, array<string, mixed>>): array<int, array<string, mixed>>  $snTypoHints
     * @param  Closure(CustomerMigration, array<string, mixed>): bool  $canModifyMigrationFromWa
     * @param  Closure(array<string, mixed>): array<int, string>  $waSenderKeys
     * @param  Closure(CustomerMigration, ?User, string): CustomerMigration  $prepareDeviceActionApprovalLocked
     * @param  Closure(): GuidedMigrationLookupService  $guidedLookup
     * @param  Closure(CustomerMigration, Customer, array<string, mixed>=): void  $syncCustomerAfterNewOnu
     * @param  Closure(CustomerMigration, array<int, string>, string=): void  $attachStoredInboundImages
     * @param  Closure(Olt): array{ok: bool, onus: list<array<string, mixed>>, error?: string}  $readUncfgOnus
     */
    public function __construct(
        public Closure $psb,
        public Closure $parser,
        public Closure $controlPlane,
        public Closure $huaweiOltOnly,
        public Closure $operationClassifier,
        public Closure $resolveCustomer,
        public Closure $verifyExistingPppoeSecret,
        public Closure $verifyTargetMigrationProfile,
        public Closure $huaweiOltOnlyWriteFlags,
        public Closure $uncfgRows,
        public Closure $snTypoHints,
        public Closure $canModifyMigrationFromWa,
        public Closure $waSenderKeys,
        public Closure $prepareDeviceActionApprovalLocked,
        public Closure $guidedLookup,
        public Closure $syncCustomerAfterNewOnu,
        public Closure $attachStoredInboundImages,
        public Closure $readUncfgOnus,
    ) {}
}
