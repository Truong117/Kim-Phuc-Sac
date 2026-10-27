<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\WeeklyPlanGoogleDocsMappingStatus;
use App\Enums\WeeklyPlanSourceType;
use App\Enums\WeeklyPlanStatus;
use App\Models\Organization;
use App\Models\OrganizationGoogleDocsFolder;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanGoogleDocsSource;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsEmployeeMappingException;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsEmployeeMappingService;
use App\Services\WeeklyPlanning\WeeklyPlanScopeService;
use App\Services\WeeklyPlanning\WeeklyPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class GoogleDocsEmployeeMappingTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_hr_maps_duplicate_titles_by_tab_id_and_never_creates_entries_or_tasks(): void
    {
        [$hr, $organizationId] = $this->manager();
        $firstEmployee = $this->employee($organizationId, 'Nguyễn An');
        $secondEmployee = $this->employee($organizationId, 'Trần Bình');
        $plan = $this->googlePlan($organizationId, $hr, 'document-a', [
            $this->tab('tab-a', 'Kế hoạch'),
            $this->tab('tab-b', 'Kế hoạch'),
        ]);

        $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}/google-docs/employee-mappings")
            ->assertOk()
            ->assertJsonPath('data.tabs.0.status', 'UNVERIFIED')
            ->assertJsonPath('data.tabs.1.status', 'UNVERIFIED')
            ->assertJsonPath('data.actions.can_update', true);

        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $firstEmployee->id, 0)
            ->assertOk()
            ->assertJsonPath('data.tabs.0.employee.id', $firstEmployee->id)
            ->assertJsonPath('data.tabs.1.employee', null);
        $this->map($hr, $plan, 'tab-b', 'VERIFIED', $secondEmployee->id, 0)
            ->assertOk()
            ->assertJsonPath('data.tabs.1.employee.id', $secondEmployee->id);

        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mappings', 2);
        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mapping_events', 2);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_exact_document_tab_history_suggests_without_auto_verifying_after_title_or_order_change(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Phương Anh');
        $first = $this->googlePlan(
            $organizationId,
            $hr,
            'document-a',
            [$this->tab('stable-tab', 'Phương Anh')],
        );
        $this->map($hr, $first, 'stable-tab', 'VERIFIED', $employee->id, 0)->assertOk();

        $renamed = $this->googlePlan(
            $organizationId,
            $hr,
            'document-a',
            [$this->tab('stable-tab', 'Phương Anh - Kế hoạch mới', 9)],
            '2026-11-09',
        );
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$renamed->id}/google-docs/employee-mappings")
            ->assertOk()
            ->assertJsonPath('data.tabs.0.status', 'UNVERIFIED')
            ->assertJsonPath('data.tabs.0.employee', null)
            ->assertJsonPath('data.tabs.0.suggestion.employee.id', $employee->id)
            ->assertJsonPath('data.tabs.0.suggestion.basis', 'CONFIRMED_EXACT')
            ->assertJsonPath('data.tabs.0.version', 0);

        $newDocument = $this->googlePlan(
            $organizationId,
            $hr,
            'document-b',
            [$this->tab('different-tab', 'Phương Anh')],
            '2026-11-16',
        );
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$newDocument->id}/google-docs/employee-mappings")
            ->assertOk()
            ->assertJsonPath('data.tabs.0.status', 'UNVERIFIED')
            ->assertJsonPath('data.tabs.0.employee', null)
            ->assertJsonPath('data.tabs.0.suggestion.employee.id', $employee->id)
            ->assertJsonPath('data.tabs.0.suggestion.basis', 'CONFIRMED_ALIAS');

        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mappings', 1);
    }

    public function test_plan_specific_mapping_history_cannot_be_mutated_by_another_plan(): void
    {
        [$hr, $organizationId] = $this->manager();
        $firstEmployee = $this->employee($organizationId, 'Nhân viên A');
        $secondEmployee = $this->employee($organizationId, 'Nhân viên B');
        $first = $this->googlePlan(
            $organizationId,
            $hr,
            'shared-document',
            [$this->tab('shared-tab', 'Nhân viên')],
        );
        $this->map($hr, $first, 'shared-tab', 'VERIFIED', $firstEmployee->id, 0)->assertOk();
        $first->forceFill(['status' => WeeklyPlanStatus::SUBMITTED])->save();

        $second = $this->googlePlan(
            $organizationId,
            $hr,
            'shared-document',
            [$this->tab('shared-tab', 'Nhân viên')],
            '2026-11-09',
        );
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$second->id}/google-docs/employee-mappings")
            ->assertOk()
            ->assertJsonPath('data.tabs.0.status', 'UNVERIFIED')
            ->assertJsonPath('data.tabs.0.suggestion.employee.id', $firstEmployee->id)
            ->assertJsonPath('data.tabs.0.suggestion.basis', 'CONFIRMED_EXACT');
        $this->map($hr, $second, 'shared-tab', 'VERIFIED', $secondEmployee->id, 0)->assertOk();
        DB::table('organization_memberships')->where('user_id', $firstEmployee->id)->update([
            'is_active' => false,
        ]);

        $this->actingAs($hr)->getJson("/api/weekly-plans/{$first->id}/google-docs/employee-mappings")
            ->assertOk()
            ->assertJsonPath('data.tabs.0.status', 'VERIFIED')
            ->assertJsonPath('data.tabs.0.employee.id', $firstEmployee->id)
            ->assertJsonPath('data.tabs.0.version', 1);
        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mapping_events', 2);
        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mappings', 2);
        $this->assertSame(1, DB::table('weekly_plan_google_docs_tab_mapping_events')
            ->where('weekly_plan_id', $first->id)
            ->count());
        $this->assertDatabaseHas('weekly_plan_google_docs_tab_mapping_events', [
            'weekly_plan_id' => $first->id,
            'new_assignee_user_id' => $firstEmployee->id,
            'mapping_version' => 1,
        ]);

        $third = $this->googlePlan(
            $organizationId,
            $hr,
            'shared-document',
            [$this->tab('shared-tab', 'Nhân viên')],
            '2026-11-16',
        );
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$third->id}/google-docs/employee-mappings")
            ->assertOk()
            ->assertJsonPath('data.tabs.0.status', 'UNVERIFIED')
            ->assertJsonPath('data.tabs.0.suggestion', null);
    }

    public function test_alias_history_is_organization_scoped_and_ambiguous_aliases_fail_closed(): void
    {
        [$hr, $organizationId] = $this->manager();
        $firstEmployee = $this->employee($organizationId, 'Nhân viên Một');
        $secondEmployee = $this->employee($organizationId, 'Nhân viên Hai');
        $first = $this->googlePlan(
            $organizationId,
            $hr,
            'document-a',
            [$this->tab('tab-a', 'Tên bí danh')],
        );
        $second = $this->googlePlan(
            $organizationId,
            $hr,
            'document-b',
            [$this->tab('tab-b', 'Tên bí danh')],
            '2026-11-09',
        );
        $this->map($hr, $first, 'tab-a', 'VERIFIED', $firstEmployee->id, 0)->assertOk();
        $this->map($hr, $second, 'tab-b', 'VERIFIED', $secondEmployee->id, 0)->assertOk();

        $third = $this->googlePlan(
            $organizationId,
            $hr,
            'document-c',
            [$this->tab('tab-c', 'Tên bí danh')],
            '2026-11-16',
        );
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$third->id}/google-docs/employee-mappings")
            ->assertOk()
            ->assertJsonPath('data.tabs.0.status', 'UNVERIFIED')
            ->assertJsonPath('data.tabs.0.suggestion', null);

        $otherOrganization = Organization::query()->create([
            'code' => 'OTHER_ALIAS_SCOPE',
            'name' => 'Other Alias Scope',
            'type' => 'internal',
            'is_active' => true,
        ]);
        $otherActor = User::factory()->create();
        $otherEmployee = User::factory()->create();
        $otherPlan = $this->googlePlan(
            $otherOrganization->id,
            $otherActor,
            'document-scope',
            [$this->tab('tab-scope', 'Chỉ tổ chức khác')],
            '2026-11-02',
        );
        DB::table('weekly_plan_google_docs_tab_mappings')->insert([
            'organization_id' => $otherOrganization->id,
            'weekly_plan_id' => $otherPlan->id,
            'document_id_sha256' => hash('sha256', 'document-scope'),
            'tab_id_sha256' => hash('sha256', 'tab-scope'),
            'tab_title_sha256' => hash('sha256', mb_strtolower('Chỉ tổ chức khác')),
            'status' => 'VERIFIED',
            'assignee_user_id' => $otherEmployee->id,
            'verified_by_user_id' => $otherActor->id,
            'verified_at' => now(),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scopedPlan = $this->googlePlan(
            $organizationId,
            $hr,
            'document-scope',
            [$this->tab('tab-scope', 'Chỉ tổ chức khác')],
            '2026-11-23',
        );
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$scopedPlan->id}/google-docs/employee-mappings")
            ->assertOk()
            ->assertJsonPath('data.tabs.0.suggestion', null);
    }

    public function test_duplicate_employee_names_never_produce_an_automatic_suggestion(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Tên Trùng');
        $this->employee($organizationId, 'Tên Trùng');
        $first = $this->googlePlan($organizationId, $hr, 'document-a', [$this->tab('tab-a', 'Tên Trùng')]);
        $this->map($hr, $first, 'tab-a', 'VERIFIED', $employee->id, 0)->assertOk();
        $second = $this->googlePlan(
            $organizationId,
            $hr,
            'document-b',
            [$this->tab('tab-b', 'Tên Trùng')],
            '2026-11-09',
        );

        $this->actingAs($hr)->getJson("/api/weekly-plans/{$second->id}/google-docs/employee-mappings")
            ->assertOk()
            ->assertJsonPath('data.tabs.0.suggestion', null);
    }

    public function test_inactive_or_moved_employee_invalidates_reuse_and_cannot_be_selected(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Nhân viên thay đổi');
        $plan = $this->googlePlan($organizationId, $hr, 'document-a', [$this->tab('tab-a', 'Nhân viên')]);
        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $employee->id, 0)->assertOk();

        DB::table('organization_memberships')->where('user_id', $employee->id)->update(['is_active' => false]);
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}/google-docs/employee-mappings")
            ->assertOk()
            ->assertJsonPath('data.tabs.0.status', 'NEEDS_REVIEW')
            ->assertJsonPath('data.tabs.0.employee', null)
            ->assertJsonPath('data.tabs.0.reason', 'EMPLOYEE_INELIGIBLE');
        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $employee->id, 1)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'GOOGLE_DOCS_EMPLOYEE_INELIGIBLE');

        $other = Organization::query()->create([
            'code' => 'OTHER_MAPPING',
            'name' => 'Other Mapping',
            'type' => 'internal',
            'is_active' => true,
        ]);
        DB::table('organization_memberships')->where('user_id', $employee->id)->update([
            'organization_id' => $other->id,
            'is_active' => true,
        ]);
        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $employee->id, 1)
            ->assertUnprocessable();
    }

    public function test_cross_organization_permission_revocation_and_client_identity_fields_fail_closed(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Đúng tổ chức');
        $plan = $this->googlePlan($organizationId, $hr, 'document-a', [$this->tab('tab-a', 'Tab A')]);

        [$outsider] = $this->manager('OTHER_ORG');
        $this->actingAs($outsider)
            ->getJson("/api/weekly-plans/{$plan->id}/google-docs/employee-mappings")
            ->assertUnauthorized();
        $this->actingAs($hr)->putJson("/api/weekly-plans/{$plan->id}/google-docs/employee-mappings", [
            'tab_id' => 'tab-a',
            'status' => 'VERIFIED',
            'employee_id' => $employee->id,
            'expected_version' => 0,
            'organization_id' => $organizationId,
            'document_id' => 'untrusted',
            'identity_verified' => true,
        ])->assertUnprocessable();

        $this->revokeManageDraft($hr);
        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $employee->id, 0)->assertForbidden();
        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mappings', 0);
    }

    public function test_strict_optimistic_locking_rejects_every_stale_update_without_extra_audit(): void
    {
        [$hr, $organizationId] = $this->manager();
        $first = $this->employee($organizationId, 'Nhân viên Một');
        $second = $this->employee($organizationId, 'Nhân viên Hai');
        $plan = $this->googlePlan($organizationId, $hr, 'document-a', [$this->tab('tab-a', 'Tab A')]);

        $this->actingAs($hr)->putJson("/api/weekly-plans/{$plan->id}/google-docs/employee-mappings", [
            'tab_id' => 'tab-a',
            'status' => 'VERIFIED',
            'employee_id' => $first->id,
            'expected_version' => '0',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mappings', 0);

        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $first->id, 0)
            ->assertOk()
            ->assertJsonPath('data.tabs.0.version', 1);
        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $first->id, 1)
            ->assertOk()
            ->assertJsonPath('data.tabs.0.version', 1);
        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mapping_events', 1);

        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $first->id, 0)
            ->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_MAPPING_CONFLICT');
        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $second->id, 0)
            ->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_MAPPING_CONFLICT');

        try {
            app(GoogleDocsEmployeeMappingService::class)->update(
                $hr,
                $plan->id,
                'tab-a',
                WeeklyPlanGoogleDocsMappingStatus::VERIFIED,
                $first->id,
                0,
            );
            $this->fail('Direct service invocation unexpectedly accepted a stale version.');
        } catch (GoogleDocsEmployeeMappingException $exception) {
            $this->assertSame('GOOGLE_DOCS_MAPPING_CONFLICT', $exception->errorCode);
        }

        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mapping_events', 1);
        $this->assertDatabaseHas('weekly_plan_google_docs_tab_mappings', [
            'assignee_user_id' => $first->id,
            'version' => 1,
        ]);

        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $second->id, 1)
            ->assertOk()
            ->assertJsonPath('data.tabs.0.employee.id', $second->id)
            ->assertJsonPath('data.tabs.0.version', 2);
        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $first->id, 1)
            ->assertConflict();
        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mapping_events', 2);
        $this->assertDatabaseHas('weekly_plan_google_docs_tab_mappings', [
            'assignee_user_id' => $second->id,
            'version' => 2,
        ]);
    }

    public function test_stale_version_precedes_current_employee_eligibility_failure(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Nhân viên bị vô hiệu hóa');
        $plan = $this->googlePlan($organizationId, $hr, 'document-a', [$this->tab('tab-a', 'Tab A')]);

        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $employee->id, 0)
            ->assertOk()
            ->assertJsonPath('data.tabs.0.version', 1);
        DB::table('organization_memberships')
            ->where('organization_id', $organizationId)
            ->where('user_id', $employee->id)
            ->update(['is_active' => false]);

        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $employee->id, 0)
            ->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_MAPPING_CONFLICT');
        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $employee->id, 1)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'GOOGLE_DOCS_EMPLOYEE_INELIGIBLE');

        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mapping_events', 1);
        $this->assertDatabaseHas('weekly_plan_google_docs_tab_mappings', [
            'assignee_user_id' => $employee->id,
            'version' => 1,
        ]);
    }

    public function test_direct_service_rechecks_current_actor_membership_before_writing(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Nhân viên hợp lệ');
        $plan = $this->googlePlan($organizationId, $hr, 'document-a', [$this->tab('tab-a', 'Tab A')]);
        $staleActorModel = User::query()->findOrFail($hr->id);

        DB::table('organization_memberships')
            ->where('organization_id', $organizationId)
            ->where('user_id', $hr->id)
            ->update(['is_active' => false]);
        $this->bindStaleScopePreflight($organizationId);

        $this->expectException(AccessDeniedHttpException::class);
        try {
            app(GoogleDocsEmployeeMappingService::class)->update(
                $staleActorModel,
                $plan->id,
                'tab-a',
                WeeklyPlanGoogleDocsMappingStatus::VERIFIED,
                $employee->id,
                0,
            );
        } finally {
            $this->assertDatabaseCount('weekly_plan_google_docs_tab_mappings', 0);
            $this->assertDatabaseCount('weekly_plan_google_docs_tab_mapping_events', 0);
        }
    }

    public function test_direct_service_rechecks_current_permission_grant_before_writing(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Nhân viên hợp lệ');
        $plan = $this->googlePlan($organizationId, $hr, 'document-a', [$this->tab('tab-a', 'Tab A')]);
        $staleActorModel = User::query()->findOrFail($hr->id);
        $this->revokeManageDraft($hr);
        $this->bindStaleScopePreflight($organizationId);

        $this->expectException(AccessDeniedHttpException::class);
        try {
            app(GoogleDocsEmployeeMappingService::class)->update(
                $staleActorModel,
                $plan->id,
                'tab-a',
                WeeklyPlanGoogleDocsMappingStatus::VERIFIED,
                $employee->id,
                0,
            );
        } finally {
            $this->assertDatabaseCount('weekly_plan_google_docs_tab_mappings', 0);
            $this->assertDatabaseCount('weekly_plan_google_docs_tab_mapping_events', 0);
        }
    }

    public function test_review_and_not_applicable_states_are_audited_without_assignee(): void
    {
        [$hr, $organizationId] = $this->manager();
        $plan = $this->googlePlan($organizationId, $hr, 'document-a', [$this->tab('tab-a', 'Tổng hợp')]);

        $this->map($hr, $plan, 'tab-a', 'NEEDS_REVIEW', null, 0)
            ->assertOk()
            ->assertJsonPath('data.tabs.0.status', 'NEEDS_REVIEW');
        $this->map($hr, $plan, 'tab-a', 'NOT_APPLICABLE', null, 1)
            ->assertOk()
            ->assertJsonPath('data.tabs.0.status', 'NOT_APPLICABLE');

        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mapping_events', 2);
        $this->assertDatabaseHas('weekly_plan_google_docs_tab_mapping_events', [
            'previous_status' => 'NEEDS_REVIEW',
            'new_status' => 'NOT_APPLICABLE',
            'actor_user_id' => $hr->id,
            'mapping_version' => 2,
        ]);
    }

    public function test_docx_plan_is_rejected_without_changing_existing_source_or_entries(): void
    {
        [$hr, $organizationId] = $this->manager();
        $plan = WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $hr->id,
            'week_start' => '2026-11-02',
            'week_end' => '2026-11-07',
            'status' => WeeklyPlanStatus::DRAFT,
            'source_type' => WeeklyPlanSourceType::UPLOADED_FILE,
            'original_filename' => 'safe.docx',
            'source_extension' => 'docx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'source_size' => 10,
            'source_sha256' => str_repeat('a', 64),
            'parse_status' => 'PARSED',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}/google-docs/employee-mappings")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'GOOGLE_DOCS_SOURCE_REQUIRED');
        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mappings', 0);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    #[DataProvider('nonDraftStatuses')]
    public function test_non_draft_plans_reject_http_and_direct_mapping_updates(string $status): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Nhân viên khóa');
        $plan = $this->googlePlan($organizationId, $hr, 'document-locked', [$this->tab('tab-a', 'Tab A')]);
        $plan->forceFill(['status' => WeeklyPlanStatus::from($status)])->save();

        $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}/google-docs/employee-mappings")
            ->assertOk()
            ->assertJsonPath('data.actions.can_update', false);
        $loaded = app(WeeklyPlanService::class)->findVisible(
            $hr,
            $plan->id,
            'weekly_plans.manage_draft',
        );
        $this->assertFalse($loaded->getAttribute('actions')['can_manage_google_docs_mappings']);
        $this->map($hr, $plan, 'tab-a', 'VERIFIED', $employee->id, 0)
            ->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_MAPPING_LOCKED');

        try {
            app(GoogleDocsEmployeeMappingService::class)->update(
                $hr,
                $plan->id,
                'tab-a',
                WeeklyPlanGoogleDocsMappingStatus::VERIFIED,
                $employee->id,
                0,
            );
            $this->fail('Direct service invocation unexpectedly updated a non-draft plan.');
        } catch (GoogleDocsEmployeeMappingException $exception) {
            $this->assertSame('GOOGLE_DOCS_MAPPING_LOCKED', $exception->errorCode);
        }

        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mappings', 0);
        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mapping_events', 0);
    }

    /** @return iterable<string, array{string}> */
    public static function nonDraftStatuses(): iterable
    {
        yield 'changes requested' => [WeeklyPlanStatus::CHANGES_REQUESTED->value];
        yield 'submitted' => [WeeklyPlanStatus::SUBMITTED->value];
        yield 'approved' => [WeeklyPlanStatus::APPROVED->value];
        yield 'published' => [WeeklyPlanStatus::PUBLISHED->value];
    }

    /** @return array{User, int} */
    private function manager(string $organizationCode = 'KPS'): array
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, organizationCode: $organizationCode);
        $roleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $roleId, true);
        $this->grantPermission(
            $roleId,
            $this->createPermission('weekly_plans.manage_draft'),
            DataScope::ORGANIZATION->value,
        );

        return [$user, $membership['organization_id']];
    }

    private function employee(int $organizationId, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        DB::table('organization_memberships')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'department_id' => null,
            'location_id' => null,
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /** @param list<array<string, mixed>> $tabs */
    private function googlePlan(
        int $organizationId,
        User $actor,
        string $documentId,
        array $tabs,
        string $weekStart = '2026-11-02',
    ): WeeklyPlan {
        $folder = OrganizationGoogleDocsFolder::query()->firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'google_folder_id' => 'folder-'.$organizationId,
                'is_active' => true,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ],
        );
        $plan = WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $actor->id,
            'week_start' => $weekStart,
            'week_end' => date('Y-m-d', strtotime($weekStart.' +5 days')),
            'status' => WeeklyPlanStatus::DRAFT,
            'source_type' => WeeklyPlanSourceType::GOOGLE_DOCS,
            'original_filename' => 'Google Docs',
            'source_extension' => 'gdoc',
            'source_mime' => 'application/vnd.google-apps.document',
            'source_size' => 0,
            'source_sha256' => hash('sha256', $documentId.'|'.$weekStart),
            'parse_status' => 'PARSED',
            'uploaded_at' => now(),
        ]);
        WeeklyPlanGoogleDocsSource::query()->create([
            'weekly_plan_id' => $plan->id,
            'organization_id' => $organizationId,
            'approved_folder_id' => $folder->id,
            'confirmed_by_user_id' => $actor->id,
            'request_id' => fake()->uuid(),
            'request_payload_sha256' => hash('sha256', fake()->uuid()),
            'google_document_id' => $documentId,
            'document_id_sha256' => hash('sha256', $documentId),
            'approved_folder_id_sha256' => hash('sha256', $folder->google_folder_id),
            'document_title' => 'Synthetic document',
            'revision_id' => null,
            'snapshot_sha256' => $plan->source_sha256,
            'tab_manifest' => $tabs,
            'passage_count' => 0,
            'character_count' => 0,
            'captured_at' => now(),
            'last_verified_at' => now(),
        ]);

        return $plan;
    }

    /** @return array<string, mixed> */
    private function tab(string $id, string $title, int $order = 1): array
    {
        return [
            'tab_id' => $id,
            'title' => $title,
            'parent_tab_id' => null,
            'nesting_level' => 0,
            'index' => 0,
            'order' => $order,
            'passage_count' => 0,
            'character_count' => 0,
            'source_group' => [],
        ];
    }

    private function map(
        User $actor,
        WeeklyPlan $plan,
        string $tabId,
        string $status,
        ?int $employeeId,
        int $expectedVersion,
    ) {
        return $this->actingAs($actor)->putJson(
            "/api/weekly-plans/{$plan->id}/google-docs/employee-mappings",
            [
                'tab_id' => $tabId,
                'status' => $status,
                'employee_id' => $employeeId,
                'expected_version' => $expectedVersion,
            ],
        );
    }

    private function revokeManageDraft(User $user): void
    {
        $membershipId = DB::table('organization_memberships')->where('user_id', $user->id)->value('id');
        $roleIds = DB::table('membership_roles')->where('membership_id', $membershipId)->pluck('role_id');
        $permissionId = DB::table('permissions')->where('code', 'weekly_plans.manage_draft')->value('id');
        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permissionId)
            ->delete();
    }

    private function bindStaleScopePreflight(int $organizationId): void
    {
        $scope = Mockery::mock(WeeklyPlanScopeService::class);
        $scope->shouldReceive('queryFor')
            ->once()
            ->with(Mockery::type(User::class), 'weekly_plans.manage_draft')
            ->andReturn(WeeklyPlan::query()->where('organization_id', $organizationId));
        $this->app->instance(WeeklyPlanScopeService::class, $scope);
    }
}
