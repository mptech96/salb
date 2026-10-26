<?php
/** Standalone MariaDB metadata collector: no application/config bootstrap. */
function verifyReleaseMetadataReader(PDO $db): array
{
    if (!str_contains(strtolower((string)$db->query('SELECT VERSION()')->fetchColumn()), 'mariadb')) {
        throw new RuntimeException('MARIADB_REQUIRED');
    }
    $identity = (string)$db->query('SELECT CURRENT_USER()')->fetchColumn();
    if (explode('@', $identity, 2)[0] !== 'sulb_metadata_ro') throw new RuntimeException('DEDICATED_READER_REQUIRED');
    if ($db->query('SELECT CURRENT_ROLE()')->fetchColumn() !== null
        || (int)$db->query('SELECT COUNT(*) FROM information_schema.APPLICABLE_ROLES')->fetchColumn() !== 0
        || (int)$db->query('SELECT COUNT(*) FROM information_schema.ENABLED_ROLES WHERE ROLE_NAME IS NOT NULL')->fetchColumn() !== 0) {
        throw new RuntimeException('ROLES_NOT_ALLOWED');
    }
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
    return ['dedicated_reader'=>true, 'roles_applicable'=>0, 'roles_enabled'=>0,
        'structured_privileges_verified'=>true, 'grant_statements_verified'=>$count,
        'trigger_visibility_complete'=>false];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
ini_set('display_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--metadata-only') throw new RuntimeException('CLI_MODE_REQUIRED');
    $tty = @fopen('/dev/tty', 'r+');
    if (!$tty) throw new RuntimeException('INTERACTIVE_TTY_REQUIRED');
    fwrite($tty, 'Password for dedicated reader sulb_metadata_ro (never application account): ');
    exec('stty -echo < /dev/tty', $unused, $status);
    if ($status !== 0) throw new RuntimeException('HIDDEN_INPUT_UNAVAILABLE');
    try { $line = fgets($tty); }
    finally { exec('stty echo < /dev/tty'); fwrite($tty, "\n"); fclose($tty); }
    if ($line === false) throw new RuntimeException('PASSWORD_INPUT_REQUIRED');
    $password = rtrim($line, "\r\n"); unset($line);
    try {
        $db = new PDO('mysql:host=localhost;dbname=sulbprod;charset=utf8mb4', 'sulb_metadata_ro', $password,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>10]);
    } finally { unset($password); }
    $gate = verifyReleaseMetadataReader($db);
    $sql = file_get_contents(__DIR__.'/release_schema_baseline_readonly.sql');
    $sql = preg_replace('/^--[^\r\n]*$/m', '', $sql);
    $sets = [];
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
        if (!preg_match('/^(SELECT\s|START TRANSACTION READ ONLY$|ROLLBACK$)/i', $statement)) throw new RuntimeException('UNEXPECTED_SQL');
        $result = $db->query($statement);
        if (str_starts_with(strtoupper($statement), 'SELECT')) $sets[] = $result->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['mode'=>'METADATA_ONLY', 'writes_performed'=>false, 'authorization'=>$gate,
        'result_sets'=>$sets], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // Never expose PDO exceptions, SQL text, grant text or credentials.
    fwrite(STDERR, "STOP: metadata collection/reader verification failed. No result is certified.\n");
    exit(1);
}
