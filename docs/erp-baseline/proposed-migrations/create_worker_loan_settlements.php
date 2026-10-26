<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        \App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
        if(Schema::hasTable('worker_loan_settlements'))throw new RuntimeException('Existing proposal requires review.');
        DB::unprepared(file_get_contents(__DIR__.'/worker_loan_settlements.sql'));
        foreach(['UPDATE','DELETE'] as $event)DB::unprepared("CREATE TRIGGER worker_settlement_deny_{$event} BEFORE {$event} ON worker_loan_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Worker settlement evidence is immutable'");
        DB::unprepared("CREATE TRIGGER worker_settlement_scope BEFORE INSERT ON worker_loan_settlements FOR EACH ROW BEGIN
          IF NOT EXISTS(SELECT 1 FROM worker_loans l JOIN workers w ON w.id=l.worker_id JOIN branches b ON b.id=NEW.branch_id
            JOIN users u ON u.id=NEW.actor_id JOIN journal_entries j ON j.id=NEW.journal_entry_id JOIN journal_entry_lines jl ON jl.id=NEW.journal_line_id
            WHERE l.id=NEW.loan_id AND l.company_id=NEW.company_id AND l.branch_id=NEW.branch_id AND l.worker_id=NEW.worker_id
            AND w.company_id=NEW.company_id AND (w.branch_id IS NULL OR w.branch_id=NEW.branch_id) AND b.company_id=NEW.company_id
            AND u.company_id=NEW.company_id AND (u.branch_id IS NULL OR u.branch_id=NEW.branch_id) AND BINARY u.name=BINARY NEW.actor_name
            AND j.company_id=NEW.company_id AND j.branch_id=NEW.branch_id AND j.status='POSTED' AND j.reversed_at IS NULL
            AND jl.journal_entry_id=j.id AND jl.company_id=NEW.company_id AND jl.party_type='WORKER' AND jl.party_id=NEW.worker_id AND jl.credit>=NEW.amount)
          THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Worker settlement scope mismatch'; END IF;
          IF NEW.receipt_voucher_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM vouchers v JOIN voucher_types t ON t.id=v.voucher_type_id
            WHERE v.id=NEW.receipt_voucher_id AND v.company_id=NEW.company_id AND v.branch_id=NEW.branch_id AND v.reference_type='WORKER'
            AND v.reference_id=NEW.worker_id AND t.type_code='RECEIPT' AND v.journal_entry_id=NEW.journal_entry_id)
          THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Worker receipt source mismatch'; END IF;
          IF NEW.salary_line_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM worker_salary_lines s JOIN worker_salary_runs r ON r.id=s.salary_run_id
            WHERE s.id=NEW.salary_line_id AND s.company_id=NEW.company_id AND s.worker_id=NEW.worker_id AND r.company_id=NEW.company_id
            AND r.branch_id=NEW.branch_id AND r.journal_entry_id=NEW.journal_entry_id AND r.status IN ('APPROVED','PAID'))
          THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Worker payroll source mismatch'; END IF;
        END");
    }
    public function down(): void
    {
        \App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
        if(Schema::hasTable('worker_loan_settlements')&&DB::table('worker_loan_settlements')->exists())throw new RuntimeException('Populated evidence cannot be dropped.');
        Schema::dropIfExists('worker_loan_settlements');
    }
};
