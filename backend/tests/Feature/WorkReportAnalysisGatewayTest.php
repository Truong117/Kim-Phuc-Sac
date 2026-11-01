<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AI\WorkReportAnalysisOutput;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WorkReportAnalysisGatewayTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    public function test_feature_uses_gateway_with_isolated_configuration_for_employee_and_summary(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $owner = User::factory()->create();
        $member = $this->createMembership($owner);
        $this->attachRole($member['membership_id'], (int) DB::table('roles')->where('code', 'OWNER')->value('id'), true);
        $employee = User::factory()->create();
        $this->createMembership($employee);
        $date = now('Asia/Ho_Chi_Minh')->subDay()->toDateString();
        $report = DB::table('daily_reports')->insertGetId([
            'organization_id' => $member['organization_id'], 'user_id' => $employee->id, 'report_date' => $date,
            'locked_at' => $date.' 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('daily_report_items')->insert(['daily_report_id' => $report, 'content' => 'Chuẩn bị nội dung nội bộ', 'result' => '', 'status' => 'IN_PROGRESS', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        config([
            'work_report_ai.enabled' => true, 'work_report_ai.driver' => 'openai',
            'work_report_ai.gateway.api_key' => 'test-only-fake-key', 'work_report_ai.gateway.monthly_budget_usd' => 5,
            'work_report_ai.gateway.input_usd_per_million_tokens' => 0.2, 'work_report_ai.gateway.output_usd_per_million_tokens' => 0.8,
        ]);
        $weeklyConfig = config('kps_ai');
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => function ($request) {
            $input = json_decode($request['input'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($request['store']);
            $this->assertTrue($request['text']['format']['strict']);
            $this->assertStringContainsString('Không chấm điểm', $request['instructions']);
            $this->assertArrayNotHasKey('email', $input);

            return Http::response([
                'status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 100],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(WorkReportAnalysisOutput::fake($input), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]]],
            ]);
        }]);
        $response = $this->actingAs($owner, 'web')->postJson('/api/ai/work-report-analyses', ['end_date' => $date, 'days' => 1])
            ->assertAccepted()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.is_mock', false)
            ->assertJsonPath('data.usage.input_tokens', 200)->assertJsonPath('data.usage.cost_microusd', 200);
        Http::assertSentCount(2);
        $this->getJson('/api/ai/work-report-analyses/'.$response->json('data.id'))->assertOk();
        Http::assertSentCount(2);
        $this->assertSame($weeklyConfig, config('kps_ai'));
        $this->assertDatabaseCount('ai_usage_records', 2);
        $this->assertDatabaseHas('ai_usage_records', ['feature' => 'work_report_analysis', 'status' => 'completed']);
    }
}
