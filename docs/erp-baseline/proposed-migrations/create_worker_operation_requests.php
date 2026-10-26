<?php
// Proposal only: never move to operational migration path without approval.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        \App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
        if (Schema::hasTable('worker_operation_requests')) throw new RuntimeException('Existing proposal requires review.');
        DB::unprepared(file_get_contents(__DIR__.'/worker_operation_requests.sql'));
        foreach (['UPDATE','DELETE'] as $event) DB::unprepared("CREATE TRIGGER worker_request_deny_{$event} BEFORE {$event} ON worker_operation_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Worker request evidence is immutable'");
        DB::unprepared("CREATE TRIGGER worker_request_scope BEFORE INSERT ON worker_operation_requests FOR EACH ROW BEGIN
          IF NOT EXISTS(SELECT 1 FROM workers w JOIN branches b ON b.id=NEW.branch_id JOIN users u ON u.id=NEW.actor_id
            WHERE w.id=NEW.worker_id AND w.company_id=NEW.company_id AND (w.branch_id IS NULL OR w.branch_id=NEW.branch_id)
            AND b.company_id=NEW.company_id AND u.company_id=NEW.company_id AND (u.branch_id IS NULL OR u.branch_id=NEW.branch_id))
          THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Worker request scope mismatch'; END IF;
          IF NEW.loan_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM worker_loans l WHERE l.id=NEW.loan_id AND l.company_id=NEW.company_id AND l.branch_id=NEW.branch_id AND l.worker_id=NEW.worker_id)
          THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Worker loan evidence mismatch'; END IF;
          IF NEW.commission_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM worker_commissions c WHERE c.id=NEW.commission_id AND c.company_id=NEW.company_id AND c.branch_id=NEW.branch_id AND c.worker_id=NEW.worker_id)
          THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Worker commission evidence mismatch'; END IF;
        END");
    }
    public function down(): void
    {
        \App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
        if (Schema::hasTable('worker_operation_requests') && DB::table('worker_operation_requests')->exists()) throw new RuntimeException('Populated evidence must be retained.');
        Schema::dropIfExists('worker_operation_requests');
    }
};
