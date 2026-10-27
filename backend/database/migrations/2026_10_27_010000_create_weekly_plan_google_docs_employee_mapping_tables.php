<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EVENTS_TABLE = 'weekly_plan_google_docs_tab_mapping_events';

    private const MAPPINGS_TABLE = 'weekly_plan_google_docs_tab_mappings';

    public function up(): void
    {
        $mappingsExist = Schema::hasTable(self::MAPPINGS_TABLE);
        $eventsExist = Schema::hasTable(self::EVENTS_TABLE);

        if ($mappingsExist || $eventsExist) {
            throw new RuntimeException(sprintf(
                'Existing Google Docs employee mapping schema detected (mappings=%s, events=%s). Migration 17 will not accept or repair partial DDL; stop and inspect the schema.',
                $mappingsExist ? 'present' : 'missing',
                $eventsExist ? 'present' : 'missing',
            ));
        }

        $missingPrerequisites = collect([
            'organizations.id' => Schema::hasTable('organizations')
                && Schema::hasColumn('organizations', 'id'),
            'users.id' => Schema::hasTable('users')
                && Schema::hasColumn('users', 'id'),
            'weekly_plans.id' => Schema::hasTable('weekly_plans')
                && Schema::hasColumn('weekly_plans', 'id'),
            'weekly_plans.organization_id' => Schema::hasTable('weekly_plans')
                && Schema::hasColumn('weekly_plans', 'organization_id'),
            'weekly_plans.source_type' => Schema::hasTable('weekly_plans')
                && Schema::hasColumn('weekly_plans', 'source_type'),
            'weekly_plan_google_docs_sources.weekly_plan_id' => Schema::hasTable('weekly_plan_google_docs_sources')
                && Schema::hasColumn('weekly_plan_google_docs_sources', 'weekly_plan_id'),
            'weekly_plan_google_docs_sources.organization_id' => Schema::hasTable('weekly_plan_google_docs_sources')
                && Schema::hasColumn('weekly_plan_google_docs_sources', 'organization_id'),
            'weekly_plan_google_docs_sources.document_id_sha256' => Schema::hasTable('weekly_plan_google_docs_sources')
                && Schema::hasColumn('weekly_plan_google_docs_sources', 'document_id_sha256'),
            'weekly_plan_google_docs_sources.tab_manifest' => Schema::hasTable('weekly_plan_google_docs_sources')
                && Schema::hasColumn('weekly_plan_google_docs_sources', 'tab_manifest'),
        ])->reject(fn (bool $present): bool => $present)->keys()->values();

        if ($missingPrerequisites->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Migration 17 prerequisites are missing: %s. Apply and verify migration 16 before retrying; no mapping schema was created.',
                $missingPrerequisites->implode(', '),
            ));
        }

        Schema::create(self::MAPPINGS_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('weekly_plan_id');
            $table->char('document_id_sha256', 64);
            $table->char('tab_id_sha256', 64);
            $table->char('tab_title_sha256', 64);
            $table->string('status', 32);
            $table->foreignId('assignee_user_id')->nullable();
            $table->foreignId('verified_by_user_id');
            $table->timestamp('verified_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(
                ['weekly_plan_id', 'tab_id_sha256'],
                'weekly_gdoc_tab_maps_plan_tab_unique',
            );
            $table->index(
                ['organization_id', 'document_id_sha256', 'tab_id_sha256', 'status'],
                'weekly_gdoc_tab_maps_org_doc_tab_status_idx',
            );
            $table->index(
                ['organization_id', 'tab_title_sha256', 'status'],
                'weekly_gdoc_tab_maps_org_title_status_idx',
            );
            $table->foreign('organization_id', 'weekly_gdoc_tab_maps_org_foreign')
                ->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('weekly_plan_id', 'weekly_gdoc_tab_maps_plan_foreign')
                ->references('id')->on('weekly_plans')->restrictOnDelete();
            $table->foreign('assignee_user_id', 'weekly_gdoc_tab_maps_assignee_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('verified_by_user_id', 'weekly_gdoc_tab_maps_verifier_foreign')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create(self::EVENTS_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mapping_id');
            $table->foreignId('organization_id');
            $table->foreignId('weekly_plan_id');
            $table->foreignId('actor_user_id');
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->foreignId('previous_assignee_user_id')->nullable();
            $table->foreignId('new_assignee_user_id')->nullable();
            $table->char('tab_title_sha256', 64);
            $table->unsignedInteger('mapping_version');
            $table->timestamp('created_at');

            $table->index(
                ['organization_id', 'tab_title_sha256', 'new_status'],
                'weekly_gdoc_tab_events_org_title_status_idx',
            );
            $table->index(
                ['mapping_id', 'created_at'],
                'weekly_gdoc_tab_events_mapping_created_idx',
            );
            $table->foreign('mapping_id', 'weekly_gdoc_tab_events_mapping_foreign')
                ->references('id')->on(self::MAPPINGS_TABLE)->restrictOnDelete();
            $table->foreign('organization_id', 'weekly_gdoc_tab_events_org_foreign')
                ->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('weekly_plan_id', 'weekly_gdoc_tab_events_plan_foreign')
                ->references('id')->on('weekly_plans')->restrictOnDelete();
            $table->foreign('actor_user_id', 'weekly_gdoc_tab_events_actor_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('previous_assignee_user_id', 'weekly_gdoc_tab_events_old_user_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('new_assignee_user_id', 'weekly_gdoc_tab_events_new_user_foreign')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable(self::EVENTS_TABLE) && DB::table(self::EVENTS_TABLE)->exists())
            || (Schema::hasTable(self::MAPPINGS_TABLE) && DB::table(self::MAPPINGS_TABLE)->exists())) {
            throw new RuntimeException(
                'Migration 17 rollback refused because verified Google Docs employee mappings or audit events exist. Use an approved retention-aware forward fix or restore plan.',
            );
        }

        Schema::dropIfExists(self::EVENTS_TABLE);
        Schema::dropIfExists(self::MAPPINGS_TABLE);
    }
};
