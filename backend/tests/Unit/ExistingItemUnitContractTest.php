<?php
namespace Tests\Unit;
use App\Services\ExistingItemUnitContract;
use Illuminate\Validation\ValidationException;

class ExistingItemUnitContractTest extends \Tests\TestCase
{
    public function test_known_weight_units_default_and_exact_factors():void
    {
        self::assertSame(1000,ExistingItemUnitContract::stock([])['commercial_to_base_factor']);
        self::assertSame(1,ExistingItemUnitContract::stock(['commercial_unit_code'=>'KG'])['commercial_to_base_factor']);
        self::assertSame('KG',ExistingItemUnitContract::stock(['base_unit_code'=>' kg ','commercial_unit_code'=>' ton ','commercial_to_base_factor'=>'1000.000'])['base_unit_code']);
    }
    public function test_invalid_and_cross_dimension_units_do_not_become_kg():void
    {
        foreach([['base_unit_code'=>'M'],['base_unit_code'=>'L'],['base_unit_code'=>'PCS'],['commercial_unit_code'=>'CARTON','commercial_to_base_factor'=>24],['commercial_unit_code'=>'KG','commercial_to_base_factor'=>1000],['commercial_unit_code'=>'TON','commercial_to_base_factor'=>24],['commercial_to_base_factor'=>0],['commercial_to_base_factor'=>-1],['commercial_to_base_factor'=>'NaN']] as $input){
            try{ExistingItemUnitContract::stock($input);self::fail('Invalid definition accepted');}catch(ValidationException $e){self::assertNotEmpty($e->errors());}
        }
    }
}
