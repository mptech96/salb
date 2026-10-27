<?php
/** Standalone metadata collector: no application/config bootstrap. */
function releaseMetadataServer(PDO $db):array
{
    $row=$db->query('SELECT VERSION() version, @@version_comment distribution')->fetch(PDO::FETCH_ASSOC);
    $maria=str_contains(strtolower($row['version']), 'mariadb');
    if(!$maria&&!preg_match('/^8\.4\./D',$row['version']))throw new RuntimeException('UNSUPPORTED_METADATA_SERVER');
    return $row+['family'=>$maria?'MariaDB':(stripos($row['distribution'],'percona')!==false?'Percona':'MySQL'),'mariadb'=>$maria];
}
function releaseMetadataQueries(PDO $db):array
{
    $server=releaseMetadataServer($db);$sql=file_get_contents(__DIR__.'/release_schema_baseline_readonly.sql');
    if(!$server['mariadb'])$sql=str_replace('@@tx_isolation','@@session.transaction_isolation',$sql);
    $sql=preg_replace('/^--[^\r\n]*$/m','',$sql);$queries=[];
    foreach(explode(';',$sql)as$query){$query=trim($query);if($query==='')continue;if(!preg_match('/^(SELECT\s|START TRANSACTION READ ONLY$|ROLLBACK$)/i',$query)&&$query!=="SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation','tx_isolation')")throw new RuntimeException('UNEXPECTED_SQL');$queries[]=$query;}
    return $queries;
}
function releaseExtendedMetadataQueries(PDO $db):array
{
    $server=releaseMetadataServer($db);
    $queries=[
        "SELECT tc.TABLE_NAME,cc.CONSTRAINT_NAME,SHA2(cc.CHECK_CLAUSE,256) clause_sha256 FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA=cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME=cc.CONSTRAINT_NAME AND tc.CONSTRAINT_TYPE='CHECK' WHERE cc.CONSTRAINT_SCHEMA=DATABASE() ORDER BY tc.TABLE_NAME,cc.CONSTRAINT_NAME",
        'SELECT TABLE_NAME,CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME',
        "SELECT TABLE_NAME,COLUMN_NAME,SHA2(GENERATION_EXPRESSION,256) expression_sha256 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND GENERATION_EXPRESSION IS NOT NULL AND GENERATION_EXPRESSION<>'' ORDER BY TABLE_NAME,COLUMN_NAME"
    ];
    if(!$server['mariadb'])$queries[]='SELECT @@global.restrict_fk_on_non_standard_key restrict_fk_on_non_standard_key, @@global.sql_require_primary_key sql_require_primary_key, @@global.log_bin log_bin, @@global.log_bin_trust_function_creators log_bin_trust_function_creators';
    return $queries;
}
function verifyReleaseMetadataReader(PDO $db): array
{
    $server=releaseMetadataServer($db);
    $identity = (string)$db->query('SELECT CURRENT_USER()')->fetchColumn();
    if (explode('@', $identity, 2)[0] !== 'sulbmetadata') throw new RuntimeException('DEDICATED_READER_REQUIRED');
    if (!in_array($db->query('SELECT CURRENT_ROLE()')->fetchColumn(),[null,'NONE'],true)
        || (int)$db->query('SELECT COUNT(*) FROM information_schema.APPLICABLE_ROLES')->fetchColumn() !== 0
        || (int)$db->query('SELECT COUNT(*) FROM information_schema.ENABLED_ROLES WHERE ROLE_NAME IS NOT NULL')->fetchColumn() !== 0) {
        throw new RuntimeException('ROLES_NOT_ALLOWED');
    }
    if(!$server['mariadb']&&trim((string)$db->query('SELECT @@global.mandatory_roles')->fetchColumn())!=='')throw new RuntimeException('MANDATORY_ROLES_NOT_ALLOWED');
    $grantee = $db->query("SELECT CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)),'@',QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1)))")->fetchColumn();
    foreach (['USER_PRIVILEGES', 'SCHEMA_PRIVILEGES', 'TABLE_PRIVILEGES', 'COLUMN_PRIVILEGES'] as $table) {
        $statement = $db->prepare("SELECT * FROM information_schema.$table WHERE GRANTEE=?");
        $statement->execute([$grantee]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['IS_GRANTABLE'] !== 'NO') throw new RuntimeException('GRANT_OPTION_NOT_ALLOWED');
            $allowed = $table === 'USER_PRIVILEGES' ? ['USAGE'] : ['SELECT', 'SHOW VIEW'];
            if (!in_array($row['PRIVILEGE_TYPE'], $allowed, true)
                || (isset($row['TABLE_SCHEMA']) && $row['TABLE_SCHEMA'] !== 'sulbprod')) {
                throw new RuntimeException('PRIVILEGE_OUTSIDE_READ_SCOPE');
            }
        }
    }
    // Supplement structured privilege/role checks: reject routine/proxy/unknown grants.
    // Grant text may contain authentication hashes: never emit or save it.
    $count = 0;
    foreach ($db->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        if (stripos($grant, 'WITH GRANT OPTION') !== false
            || !preg_match('/^GRANT (.*?) ON (.*?) TO /s', $grant, $match)) {
            throw new RuntimeException('UNVERIFIED_GRANT');
        }
        $privileges = array_map('trim', explode(',', $match[1]));
        $scope = str_replace('`', '', $match[2]);
        if (!(($privileges === ['USAGE'] && $scope === '*.*')
            || ($scope === 'sulbprod.*' && !array_diff($privileges, ['SELECT', 'SHOW VIEW'])))) {
            throw new RuntimeException('UNVERIFIED_GRANT_SCOPE');
        }
        $count++;
    }
    if (!$count) throw new RuntimeException('NO_GRANT_EVIDENCE');
    return ['dedicated_reader'=>true, 'server'=>$server, 'roles_applicable'=>0, 'roles_enabled'=>0,
        'structured_privileges_verified'=>true, 'grant_statements_verified'=>$count,
        'trigger_visibility_complete'=>false];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
ini_set('display_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--metadata-only') throw new RuntimeException('CLI_MODE_REQUIRED');
    $tty = @fopen('/dev/tty', 'r+');
    if (!$tty) throw new RuntimeException('INTERACTIVE_TTY_REQUIRED');
    fwrite($tty, 'Password for dedicated reader sulbmetadata (never application account): ');
    exec('stty -echo < /dev/tty', $unused, $status);
    if ($status !== 0) throw new RuntimeException('HIDDEN_INPUT_UNAVAILABLE');
    try { $line = fgets($tty); }
    finally { exec('stty echo < /dev/tty'); fwrite($tty, "\n"); fclose($tty); }
    if ($line === false) throw new RuntimeException('PASSWORD_INPUT_REQUIRED');
    $password = rtrim($line, "\r\n"); unset($line);
    try {
        $db = new PDO('mysql:host=localhost;dbname=sulbprod;charset=utf8mb4', 'sulbmetadata', $password,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>10]);
    } finally { unset($password); }
    $gate = verifyReleaseMetadataReader($db);
    $sets = [];
    foreach (releaseMetadataQueries($db) as $statement) {
        $result = $db->query($statement);
        if (str_starts_with(strtoupper($statement), 'SELECT')||str_starts_with($statement,'SHOW SESSION VARIABLES')) $sets[] = $result->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['mode'=>'METADATA_ONLY', 'writes_performed'=>false, 'authorization'=>$gate,
        'result_sets'=>$sets], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // Never expose PDO exceptions, SQL text, grant text or credentials.
    fwrite(STDERR, "STOP: metadata collection/reader verification failed. No result is certified.\n");
    exit(1);
}
