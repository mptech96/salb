<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;

/** Global dictionary is provisioned by approved schema administration, never tenant requests. */
final class UnitCatalog
{
    public function all():array
    {return DB::table('uom_units')->orderBy('id')->get()->map(fn($u)=>(array)$u)->all();}
    public function requireUnit(int $id):object
    {$u=DB::table('uom_units')->where('id',$id)->first();if(!$u)throw new \RuntimeException('UOM_UNIT_NOT_FOUND');return $u;}
}
