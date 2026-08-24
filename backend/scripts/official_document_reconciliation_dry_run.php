<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$publicRoot = storage_path('app/public/official-documents');
$privateRoot = storage_path('app/private/official-documents');
$manifestPath = $argv[1] ?? storage_path('app/reconciliation/official-documents-dry-run.json');

function normalizedRelativePath(?string $path): ?string
{
    if ($path === null || str_contains($path, "\0")) return null;
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)) return null;
    $parts = explode('/', $path);
    if (in_array('..', $parts, true) || in_array('.', $parts, true) || $parts[0] !== 'official-documents') return null;
    return implode('/', array_filter($parts, static fn (string $part): bool => $part !== ''));
}

function pathWithin(string $path, string $root): bool
{
    $normalize = static fn (string $value): string => rtrim(strtolower(str_replace('\\', '/', $value)), '/');
    $path = $normalize($path);$root = $normalize($root);
    return $path === $root || str_starts_with($path, $root.'/');
}

function hasSymlinkComponent(string $path, string $stopAt): bool
{
    $cursor = $path;
    while (pathWithin($cursor, $stopAt) && $cursor !== dirname($cursor)) {
        if (is_link($cursor)) return true;
        if (rtrim($cursor, '\\/') === rtrim($stopAt, '\\/')) break;
        $cursor = dirname($cursor);
    }
    return false;
}

$connection = DB::connection();
if ($connection->getDriverName() === 'mysql') DB::statement('SET TRANSACTION READ ONLY');
$records = DB::transaction(function (): array {
    return DB::table('official_document_attachments as a')
        ->leftJoin('official_documents as d', function ($join): void {
            $join->on('d.id','=','a.document_id')->on('d.company_id','=','a.company_id');
        })
        ->orderBy('a.id')
        ->get(['a.id','a.company_id','d.branch_id','a.document_id','a.file_path'])
        ->map(static fn (object $row): array => (array) $row)->all();
});

$physical = [];
if (is_dir($publicRoot)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($publicRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $absolute = $file->getPathname();
        $relative = 'official-documents/'.str_replace('\\','/',substr($absolute,strlen($publicRoot)+1));
        $physical[$relative] = ['absolute'=>$absolute,'size'=>$file->getSize(),'sha256'=>hash_file('sha256',$absolute)];
    }
}

$referenceCounts = [];
foreach ($records as $record) {
    $canonical = normalizedRelativePath($record['file_path']);
    if ($canonical !== null) $referenceCounts[$canonical] = ($referenceCounts[$canonical] ?? 0) + 1;
}

$manifest = [];$referenced = [];
foreach ($records as $record) {
    $canonical = normalizedRelativePath($record['file_path']);
    $entry = ['record_id'=>(int)$record['id'],'company_id'=>(int)$record['company_id'],'branch_id'=>$record['branch_id']!==null?(int)$record['branch_id']:null,
        'document_id'=>(int)$record['document_id'],'old_path'=>$record['file_path'],'proposed_new_path'=>$canonical,'file_size'=>null,'sha256'=>null,'status'=>'MISSING_FILE'];
    if ($canonical === null) {$entry['status']='PATH_INVALID';$manifest[]=$entry;continue;}
    $referenced[$canonical]=true;$source=$publicRoot.DIRECTORY_SEPARATOR.substr($canonical,strlen('official-documents/'));
    $destination=$privateRoot.DIRECTORY_SEPARATOR.substr($canonical,strlen('official-documents/'));
    if (!pathWithin($source,$publicRoot)||!pathWithin($destination,$privateRoot)||hasSymlinkComponent($source,$publicRoot)||hasSymlinkComponent(dirname($destination),$privateRoot)) {$entry['status']='PATH_INVALID';$manifest[]=$entry;continue;}
    if (($referenceCounts[$canonical]??0)>1) $entry['status']='DUPLICATE_REFERENCE';
    elseif (!isset($physical[$canonical])) $entry['status']='MISSING_FILE';
    elseif (file_exists($destination)) $entry['status']='DESTINATION_CONFLICT';
    else $entry['status']='READY';
    if(isset($physical[$canonical])){$entry['file_size']=$physical[$canonical]['size'];$entry['sha256']=$physical[$canonical]['sha256'];}
    $manifest[]=$entry;
}
foreach ($physical as $relative=>$file) if(!isset($referenced[$relative]))$manifest[]=['record_id'=>null,'company_id'=>null,'branch_id'=>null,'document_id'=>null,
    'old_path'=>$relative,'proposed_new_path'=>'official-documents/'.substr($relative,strlen('official-documents/')),'file_size'=>$file['size'],'sha256'=>$file['sha256'],'status'=>'ORPHAN_FILE'];

$hashGroups=[];foreach($physical as$relative=>$file)$hashGroups[$file['sha256']][]=$relative;
$duplicateHashes=array_filter($hashGroups,static fn(array $paths):bool=>count($paths)>1);
$counts=array_count_values(array_column($manifest,'status'));
$report=['generated_at'=>now()->toIso8601String(),'mode'=>'DRY_RUN_READ_ONLY','roots'=>['public'=>$publicRoot,'private'=>$privateRoot],
    'summary'=>['database_attachment_records'=>count($records),'physical_public_files'=>count($physical),'matched_records'=>count(array_filter($manifest,static fn(array $e):bool=>in_array($e['status'],['READY','DESTINATION_CONFLICT','DUPLICATE_REFERENCE'],true)&&$e['record_id']!==null&&$e['file_size']!==null)),
        'missing_files'=>$counts['MISSING_FILE']??0,'orphan_files'=>$counts['ORPHAN_FILE']??0,'duplicate_references'=>$counts['DUPLICATE_REFERENCE']??0,
        'duplicate_physical_hash_groups'=>count($duplicateHashes),'invalid_paths'=>$counts['PATH_INVALID']??0,'destination_conflicts'=>$counts['DESTINATION_CONFLICT']??0,
        'ready_files'=>$counts['READY']??0,'total_public_bytes'=>array_sum(array_column($physical,'size')),
        'bytes_ready_to_move'=>array_sum(array_map(static fn(array $e):int=>$e['status']==='READY'?(int)$e['file_size']:0,$manifest)),
        'database_updates_required'=>0],
    'duplicate_hash_groups'=>$duplicateHashes,'manifest'=>$manifest];

$parent=dirname($manifestPath);if(!is_dir($parent)&&!mkdir($parent,0775,true)&&!is_dir($parent))throw new RuntimeException("Cannot create manifest directory: {$parent}");
if(file_put_contents($manifestPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))===false)throw new RuntimeException("Cannot write manifest: {$manifestPath}");
echo json_encode(['manifest'=>$manifestPath,'summary'=>$report['summary']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
