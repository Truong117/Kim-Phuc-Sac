<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCE_TABLE = 'weekly_plan_google_docs_sources';

    public function up(): void
    {
        $sourceTypeExists = Schema::hasColumn('weekly_plans', 'source_type');
        $sourceTableExists = Schema::hasTable(self::SOURCE_TABLE);

        if ($sourceTypeExists || $sourceTableExists) {
            throw new RuntimeException(sprintf(
                'Existing durable Google Docs source schema detected (source_type=%s, source_table=%s). Migration 16 will not accept or repair partial DDL; stop and inspect the schema.',
                $sourceTypeExists ? 'present' : 'missing',
                $sourceTableExists ? 'present' : 'missing',
            ));
        }

        Schema::table('weekly_plans', function (Blueprint $table): void {
            $table->string('source_type', 32)
                ->default('UPLOADED_FILE')
                ->after('status');
        });

        Schema::create(self::SOURCE_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_plan_id');
            $table->foreignId('organization_id');
            $table->foreignId('approved_folder_id');
            $table->foreignId('confirmed_by_user_id');
            $table->uuid('request_id');
            $table->char('request_payload_sha256', 64);
            $table->longText('google_document_id');
            $table->char('document_id_sha256', 64);
            $table->char('approved_folder_id_sha256', 64);
            $table->longText('document_title');
            $table->longText('revision_id')->nullable();
            $table->char('snapshot_sha256', 64);
            $table->longText('tab_manifest');
            $table->unsignedInteger('passage_count');
            $table->unsignedBigInteger('character_count');
            $table->timestamp('captured_at');
            $table->timestamp('last_verified_at');
            $table->timestamp('stale_at')->nullable();
            $table->timestamps();

            $table->unique('weekly_plan_id', 'weekly_google_sources_plan_unique');
            $table->unique(
                ['organization_id', 'request_id'],
                'weekly_google_sources_org_request_unique',
            );
            $table->index(
                ['organization_id', 'document_id_sha256'],
                'weekly_google_sources_org_document_idx',
            );
            $table->foreign('weekly_plan_id', 'weekly_google_sources_plan_foreign')
                ->references('id')
                ->on('weekly_plans')
                ->cascadeOnDelete();
            $table->foreign('organization_id', 'weekly_google_sources_org_foreign')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
            $table->foreign('approved_folder_id', 'weekly_google_sources_folder_foreign')
                ->references('id')
                ->on('organization_google_docs_folders')
                ->restrictOnDelete();
            $table->foreign('confirmed_by_user_id', 'weekly_google_sources_actor_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $hasPersistedSource = Schema::hasTable(self::SOURCE_TABLE)
            && DB::table(self::SOURCE_TABLE)->exists();
        $hasGoogleDocsPlan = Schema::hasColumn('weekly_plans', 'source_type')
            && DB::table('weekly_plans')
                ->where('source_type', 'GOOGLE_DOCS')
                ->exists();

        if ($hasPersistedSource || $hasGoogleDocsPlan) {
            throw new RuntimeException(
                'Migration 16 rollback refused because durable Google Docs sources or GOOGLE_DOCS plans exist. Use an approved retention-aware forward fix or restore plan.',
            );
        }

        Schema::dropIfExists(self::SOURCE_TABLE);

        if (Schema::hasColumn('weekly_plans', 'source_type')) {
            Schema::table('weekly_plans', function (Blueprint $table): void {
                $table->dropColumn('source_type');
            });
        }
    }
};
