<?php
// Review proposal only; deliberately outside operational migrations.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables=['voucher_operation_requests','voucher_correction_audits'];
    public function up(): void
    {
        \App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
        if(!Schema::hasTable('voucher_invoice_allocations'))throw new RuntimeException('Historical accounting proposal must be installed first.');
        $version=(string)DB::selectOne('SELECT VERSION() AS v')->v;
        preg_match('/\d+\.\d+\.\d+/',preg_replace('/^5\.5\.5-/','',$version),$matches);
        if(!$matches||version_compare($matches[0],stripos($version,'MariaDB')!==false?'10.2.1':'8.0.16','<'))throw new RuntimeException('Enforced CHECK support required.');
        foreach(['companies'=>'bigint','vouchers'=>'bigint','branches'=>'bigint unsigned','users'=>'bigint unsigned','journal_entries'=>'bigint unsigned']as$table=>$type){
            $column=DB::selectOne('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,'id']);
            $engine=DB::selectOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
            if(!$column||preg_replace('/\(\d+\)/','',strtolower($column->COLUMN_TYPE))!==$type||!$engine||strtoupper($engine->ENGINE)!=='INNODB')throw new RuntimeException('Unexpected parent key or engine: '.$table);
        }
        foreach($this->tables as$table)if(Schema::hasTable($table))throw new RuntimeException('Existing metadata schema requires review.');
        foreach(array_filter(array_map('trim',explode(';',file_get_contents(__DIR__.'/voucher_operation_metadata.sql'))))as$sql)DB::unprepared($sql);
        foreach($this->tables as$table){
            foreach(['UPDATE','DELETE']as$event)DB::unprepared("CREATE TRIGGER {$table}_deny_".strtolower($event)." BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable voucher evidence'");
        }
        DB::unprepared("CREATE TRIGGER vor_scope BEFORE INSERT ON voucher_operation_requests FOR EACH ROW BEGIN
          IF NOT EXISTS (SELECT 1 FROM vouchers v JOIN branches b ON b.id=NEW.branch_id JOIN users u ON u.id=NEW.actor_id JOIN journal_entries j ON j.id=NEW.journal_entry_id JOIN voucher_types t ON t.id=v.voucher_type_id
           WHERE v.id=NEW.voucher_id AND v.company_id=NEW.company_id AND v.branch_id=NEW.branch_id AND b.company_id=NEW.company_id AND u.company_id=NEW.company_id AND (u.branch_id IS NULL OR u.branch_id=NEW.branch_id)
           AND j.company_id=NEW.company_id AND j.branch_id=NEW.branch_id AND j.source_type='VOUCHER' AND j.source_id=v.id AND j.status='POSTED' AND v.journal_entry_id=j.id
           AND BINARY NEW.operation=BINARY CONCAT('CREATE_',t.type_code) AND BINARY NEW.actor_name=BINARY u.name)
          THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Voucher request scope mismatch'; END IF;
        END");
        DB::unprepared("CREATE TRIGGER vca_scope BEFORE INSERT ON voucher_correction_audits FOR EACH ROW BEGIN
          IF NOT EXISTS (SELECT 1 FROM vouchers v JOIN journal_entries o ON o.id=NEW.original_journal_id JOIN journal_entries r ON r.id=NEW.inverse_journal_id JOIN users u ON u.id=NEW.actor_id JOIN branches b ON b.id=NEW.branch_id
           WHERE v.id=NEW.voucher_id AND v.company_id=NEW.company_id AND v.branch_id=NEW.branch_id AND b.company_id=NEW.company_id AND u.company_id=NEW.company_id AND (u.branch_id IS NULL OR u.branch_id=NEW.branch_id) AND BINARY NEW.actor_name=BINARY u.name
           AND BINARY v.voucher_number=BINARY NEW.original_voucher_number AND v.journal_entry_id=o.id AND o.company_id=NEW.company_id AND o.branch_id=NEW.branch_id AND o.source_type='VOUCHER' AND o.source_id=v.id
           AND r.company_id=NEW.company_id AND r.branch_id=NEW.branch_id AND r.reversal_of_id=o.id AND r.status='POSTED')
          THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction evidence scope mismatch'; END IF;
        END");
    }
    public function down(): void
    {
        \App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
        foreach($this->tables as$table)if(Schema::hasTable($table)&&DB::table($table)->exists())throw new RuntimeException('Populated voucher evidence cannot be dropped.');
        foreach(array_reverse($this->tables)as$table)Schema::dropIfExists($table);
    }
};
