<?php
namespace Tests\Support;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
final class InventorySequenceFixture
{
 public static function install():void{
  if(Schema::hasTable('sulb_document_sequences'))return;
  Schema::create('sulb_document_sequences',function(Blueprint $t){
   $t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->default(0);
   $t->string('document_type',50);$t->unsignedSmallInteger('document_year');$t->unsignedBigInteger('next_number')->default(1);$t->timestamps();
   $t->unique(['company_id','branch_id','document_type','document_year'],'uq_sulb_document_sequence');
  });
  \Illuminate\Support\Facades\DB::table('sulb_document_sequences')->insert(['company_id'=>1,'branch_id'=>0,'document_type'=>'INVENTORY_LOT','document_year'=>2026,'next_number'=>1]);
 }
}
