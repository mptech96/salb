<?php
namespace Tests\Unit;
class UomContractGateTest extends \Tests\TestCase
{
 public function test_disabled_contract_gate_rejects_before_any_write():void{
  config(['uom.contract_testing_enabled'=>false]);$this->expectExceptionMessage('UOM_CONTRACT_NOT_ACTIVATED');\App\Services\Uom\ContractTestingGuard::assert();
 }
 public function test_config_override_does_not_authorize_non_owned_database():void{
  config(['uom.contract_testing_enabled'=>true]);$this->expectExceptionMessage('UOM_CONTRACT_NOT_ACTIVATED');\App\Services\Uom\ContractTestingGuard::assert();
 }
}
