<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FOLDERS_TABLE = 'organization_google_docs_folders';

    private const EVENTS_TABLE = 'organization_google_docs_folder_events';

    public function up(): void
    {
        $foldersTableExists = Schema::hasTable(self::FOLDERS_TABLE);
        $eventsTableExists = Schema::hasTable(self::EVENTS_TABLE);

        if ($foldersTableExists || $eventsTableExists) {
            throw new RuntimeException(sprintf(
                'Existing Google Docs registry schema detected (folders=%s, events=%s). Migration 15 will not accept or repair existing tables; stop and inspect columns, indexes, and foreign keys.',
                $foldersTableExists ? 'present' : 'missing',
                $eventsTableExists ? 'present' : 'missing',
            ));
        }

        Schema::create(self::FOLDERS_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('google_folder_id', 200);
            $table->boolean('is_active')->default(true);
            $table->foreignId('approved_by_user_id');
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique('organization_id', 'org_google_docs_folders_org_unique');
            $table->unique('google_folder_id', 'org_google_docs_folders_folder_unique');
            $table->index(['is_active', 'organization_id'], 'org_google_docs_folders_active_idx');
            $table->foreign('organization_id', 'org_google_docs_folders_org_foreign')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
            $table->foreign('approved_by_user_id', 'org_google_docs_folders_approver_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        Schema::create(self::EVENTS_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folder_id');
            $table->foreignId('organization_id');
            $table->foreignId('actor_user_id');
            $table->string('event_type', 32);
            $table->string('previous_google_folder_id', 200)->nullable();
            $table->string('current_google_folder_id', 200)->nullable();
            $table->timestamp('created_at');

            $table->index(
                ['organization_id', 'created_at'],
                'org_google_docs_events_org_created_idx',
            );
            $table->foreign('folder_id', 'org_google_docs_events_folder_foreign')
                ->references('id')
                ->on('organization_google_docs_folders')
                ->restrictOnDelete();
            $table->foreign('organization_id', 'org_google_docs_events_org_foreign')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
            $table->foreign('actor_user_id', 'org_google_docs_events_actor_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists(self::EVENTS_TABLE);
        Schema::dropIfExists(self::FOLDERS_TABLE);
    }
};
