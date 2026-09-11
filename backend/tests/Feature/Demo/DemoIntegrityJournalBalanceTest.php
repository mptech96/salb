<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Services\Demo\DemoIntegrityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DemoIntegrityJournalBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::connection()->getDriverName());
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
        });
        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id');
            $table->decimal('debit', 18, 3)->default(0);
            $table->decimal('credit', 18, 3)->default(0);
        });
    }

    public function test_balanced_posted_journals_pass_and_unbalanced_journals_are_detected_per_company(): void
    {
        $this->journal(10, 'POSTED', 125, 125, 'EXPENSE', 1);
        $this->journal(10, 'DRAFT', 100, 25, 'MANUAL', 2);
        $this->journal(20, 'POSTED', 100, 25, 'EXPENSE', 3);
        $integrity = app(DemoIntegrityService::class);

        self::assertSame(0, $integrity->countUnbalancedPostedJournals(10));
        self::assertSame(1, $integrity->countUnbalancedPostedJournals(20));

        $this->journal(10, 'POSTED', 75, 74.999, 'EXPENSE', 4);
        self::assertSame(1, $integrity->countUnbalancedPostedJournals(10));
    }

    public function test_duplicate_source_detection_is_company_scoped_and_uses_only_grouped_columns(): void
    {
        $this->journal(10, 'POSTED', 10, 10, 'EXPENSE', 7);
        $this->journal(10, 'POSTED', 10, 10, 'EXPENSE', 7);
        $this->journal(20, 'POSTED', 10, 10, 'EXPENSE', 7);
        $integrity = app(DemoIntegrityService::class);

        self::assertSame(1, $integrity->countDuplicateSourceJournals(10));
        self::assertSame(0, $integrity->countDuplicateSourceJournals(20));

        $source = file_get_contents(app_path('Services/Demo/DemoIntegrityService.php'));
        self::assertStringContainsString('e.id AS journal_entry_id', $source);
        self::assertStringContainsString('source_type, source_id, COUNT(*) AS duplicate_count', $source);
    }

    private function journal(int $companyId, string $status, float $debit, float $credit, string $sourceType, int $sourceId): void
    {
        $id = DB::table('journal_entries')->insertGetId([
            'company_id' => $companyId,
            'status' => $status,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
        DB::table('journal_entry_lines')->insert([
            ['journal_entry_id' => $id, 'debit' => $debit, 'credit' => 0],
            ['journal_entry_id' => $id, 'debit' => 0, 'credit' => $credit],
        ]);
    }
}
