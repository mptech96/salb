<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('support_sessions')) {
            throw new RuntimeException('support_sessions already exists; reconcile it before running migration 000023.');
        }

        Schema::create('support_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('support_session_id')->unique();
            $table->bigInteger('platform_user_id');
            $table->bigInteger('company_id');
            $table->bigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->string('access_level', 20)->default('READ_ONLY');
            $table->json('capabilities_json')->nullable();
            $table->text('reason');
            $table->string('ticket_reference', 150);
            $table->string('status', 20)->default('ACTIVE');
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('ended_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['company_id','status'],'idx_support_company_status');
            $table->index(['platform_user_id','created_at'],'idx_support_actor_created');
            $table->index(['status','expires_at'],'idx_support_status_expires');
            $table->index('personal_access_token_id','idx_support_token');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_type', 30)->nullable()->after('user_id');
            $table->string('actor_role_code', 100)->nullable()->after('actor_type');
            $table->uuid('support_session_id')->nullable()->after('actor_role_code');
            $table->string('ticket_reference', 150)->nullable()->after('support_session_id');
            $table->text('reason')->nullable()->after('ticket_reference');
            $table->json('scope_json')->nullable()->after('reason');
            $table->json('before_json')->nullable()->after('scope_json');
            $table->json('after_json')->nullable()->after('before_json');
            $table->string('result', 20)->nullable()->after('after_json');
            $table->uuid('request_id')->nullable()->after('result');
            $table->index('support_session_id','idx_audit_support_session');
            $table->index(['result','created_at'],'idx_audit_result_created');
            $table->index(['user_id','created_at'],'idx_audit_actor_created');
            $table->index(['company_id','created_at'],'idx_audit_company_created');
            $table->index('request_id','idx_audit_request');
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });

        if (!Schema::hasColumn('permissions','permission_scope')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->string('permission_scope',20)->default('COMPANY')->after('module_name');
                $table->index(['permission_scope','permission_code'],'idx_permissions_scope_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('permissions','permission_scope')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->dropIndex('idx_permissions_scope_code');
                $table->dropColumn('permission_scope');
            });
        }
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('idx_audit_support_session');
            $table->dropIndex('idx_audit_result_created');
            $table->dropIndex('idx_audit_actor_created');
            $table->dropIndex('idx_audit_company_created');
            $table->dropIndex('idx_audit_request');
            $table->dropColumn(['actor_type','actor_role_code','support_session_id','ticket_reference','reason','scope_json','before_json','after_json','result','request_id']);
        });
        Schema::dropIfExists('support_sessions');
    }
};
