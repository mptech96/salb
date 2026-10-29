<?php
require_once __DIR__.'/review-account-evidence.php';
const SULB_BACKUP_RECEIPT_FILES=['snapshot.enc','manifest-private.json','backend.assets.enc','frontend.assets.enc','backup-verification.json','backup-metadata.json','source-inventory.json'];
function validateBackupReceipt(array $proof,array $release,array $manifest,array $verified,array $metadata,int $now,string $issuer):void {
    if(($proof['format']??'')!=='SULB_CURRENT_UPGRADE_AUTHORIZATION_V1'||($proof['issuer_entry_sha256']??null)!==$issuer||($release['production_upgrade']['qualified_backup_issuer_sha256']??null)!==$issuer)throw new RuntimeException('UPGRADE_QUALIFIED_BACKUP_ISSUER_REQUIRED');
    if(($proof['database']??'')!=='sulbprod'||!is_int($proof['created_unix']??null)||$proof['created_unix']>$now||$now-$proof['created_unix']>3600||!is_int($metadata['created_unix']??null)||$metadata['created_unix']>$now||$now-$metadata['created_unix']>3600)throw new RuntimeException('UPGRADE_BACKUP_WINDOW_REQUIRED');
    foreach(['server_uuid','approved_commit']as$k)if(empty($proof[$k])||($metadata[$k]??null)!==$proof[$k])throw new RuntimeException('UPGRADE_BACKUP_SOURCE_IDENTITY');
    if(($metadata['format']??'')!=='SULB_ENCRYPTED_RELEASE_BACKUP_V1'||($metadata['consistent_snapshot']??false)!==true||($metadata['code_archives_verified']??false)!==true||($verified['backup_restore_verification']??'')!=='PASS'||($verified['production_connected']??null)!==false||($verified['owned_server_stopped']??null)!==true||($verified['host_production_identities_unchanged']??null)!==true||($verified['row_digests_match']??null)!==true||($verified['code_assets_decrypted_verified']??null)!==true||($verified['foreign_key_orphans']??null)!==0)throw new RuntimeException('UPGRADE_EXACT_RESTORE_EVIDENCE_REQUIRED');
    $archive=$manifest['file_sha256']??null;if(!is_string($archive)||!preg_match('/^[a-f0-9]{64}$/D',$archive)||($verified['archive_sha256']??null)!==$archive||($metadata['archive_sha256']??null)!==$archive||($proof['backup_files_sha256']['snapshot.enc']??null)!==$archive)throw new RuntimeException('UPGRADE_BACKUP_ARCHIVE_BINDING');
    $keys=array_keys($proof['backup_files_sha256']??[]);sort($keys);$expected=SULB_BACKUP_RECEIPT_FILES;sort($expected);if($keys!==$expected)throw new RuntimeException('UPGRADE_EXACT_ENCRYPTED_FILES_REQUIRED');
}
function validateBackupReceiptFiles(string $dir,array $proof,array $release,string $root):void {
    foreach(SULB_BACKUP_RECEIPT_FILES as$name){$file=$dir.'/'.$name;accountPrivate($file);$sha=$proof['backup_files_sha256'][$name]??'';if(!preg_match('/^[a-f0-9]{64}$/D',$sha)||!hash_equals($sha,hash_file('sha256',$file)))throw new RuntimeException('UPGRADE_EXACT_BACKUP_HASH_REQUIRED');}
    $read=fn($name)=>json_decode(file_get_contents($dir.'/'.$name),true,512,JSON_THROW_ON_ERROR);
    validateBackupReceipt($proof,$release,$read('manifest-private.json'),$read('backup-verification.json'),$read('backup-metadata.json'),time(),hash_file('sha256',$root.'/ops/sulb-rc/release-backup.php'));
}
