<?php
/** Synthetic payload supplied by the real Python packet generator; never reads private production evidence. */
require __DIR__.'/writer-signoff-policy.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$original=json_decode($input['source_json'],true,512,JSON_THROW_ON_ERROR);
$facts=json_decode($input['facts_json'],true,512,JSON_THROW_ON_ERROR);
$checks=0;
$ok=function(bool $v)use(&$checks){if(!$v)throw new RuntimeException('SOURCE_FACTS_TEST_'.($checks+1));$checks++;};
$reject=function(array $source,array $evidence,string $reason,?array $reviewed=null)use($ok){
    try{writerAssertSourceFacts($source,$evidence,$reviewed);}catch(RuntimeException $e){$ok($e->getMessage()===$reason);return;}
    $ok(false);
};
// Reproduces 42f6703's failure with the actual generator: only object member order differs.
$ok($original['writer_linkage']['service_schedule_candidates']!==$facts['service_candidates']);
$before=serialize([$original,$facts]);
writerAssertSourceFacts($original,$facts);$ok(true);
$ok(serialize([$original,$facts])===$before);
$ok(hash('sha256',$input['source_json'])===$facts['source_profile_sha256']);
$source=$original;$source['writer_linkage']['missing_proofs']=array_reverse($source['writer_linkage']['missing_proofs']);
$reject($source,$facts,'WRITER_SOURCE_PROOF_CATEGORIES_CHANGED');
foreach(['path'=>'/SYNTHETIC_CHANGED','sha256'=>str_repeat('f',64),'approved'=>0,'owner'=>'SYNTHETIC_CHANGED']as$key=>$value){
    $changed=$facts;$changed['service_candidates'][0][$key]=$value;
    $reject($original,$changed,'WRITER_SOURCE_SERVICE_FACTS_CHANGED');
}
$changed=$facts;unset($changed['service_candidates'][0]['owner']);
$reject($original,$changed,'WRITER_SOURCE_SERVICE_FACTS_CHANGED');
$changed=$facts;$changed['service_candidates'][0]['candidate_principals']=array_reverse($changed['service_candidates'][0]['candidate_principals']);
$reject($original,$changed,'WRITER_SOURCE_SERVICE_FACTS_CHANGED');
$changed=$facts;$changed['service_candidates'][0]['nested_synthetic_metadata']['flag']=0;
$reject($original,$changed,'WRITER_SOURCE_SERVICE_FACTS_CHANGED');
$changed=$facts;$changed['service_candidates'][0]['nested_synthetic_metadata']['extra']='NOT_IN_SOURCE';
$reject($original,$changed,'WRITER_SOURCE_SERVICE_FACTS_CHANGED');
$source=$original;$source['writer_linkage']['service_schedule_candidates'][]=$source['writer_linkage']['service_schedule_candidates'][0];
$source['writer_linkage']['service_schedule_candidates'][1]['path']='/etc/SYNTHETIC_SECOND.conf';
$changed=$facts;$changed['service_candidates']=$source['writer_linkage']['service_schedule_candidates'];
$changed['service_candidates']=array_reverse($changed['service_candidates']);
$reject($source,$changed,'WRITER_SOURCE_SERVICE_FACTS_CHANGED');
$reviewed=json_decode($input['sorted_source_json'],true,512,JSON_THROW_ON_ERROR);
writerAssertSourceFacts($original,$facts,$reviewed);$ok(true);
$reviewed['database_accounts'][0]['locked']=(int)$reviewed['database_accounts'][0]['locked'];
$reject($original,$facts,'WRITER_SOURCE_FACTS_NOT_PRESERVED',$reviewed);
$ok(!writerSourceFactsEqual(['value'=>null],[]));
$ok(!writerSourceFactsEqual(['x','y'],['y','x']));
$ok(!writerSourceFactsEqual(1,'1'));
$ok(!writerSourceFactsEqual(['x'=>1],['x'=>1,'y'=>null]));
echo 'PASS '.$checks.' real Python/PHP source-facts compatibility and tamper checks; object_order_bug_reproduced=true; production_connections=0'.PHP_EOL;
