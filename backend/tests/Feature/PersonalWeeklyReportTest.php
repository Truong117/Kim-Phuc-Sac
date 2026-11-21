<?php

namespace Tests\Feature;

use App\Models\ReportAutomationCycle;
use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Services\AI\WorkReportAnalysisService;
use App\Services\AI\WorkReportAnalysisWorker;
use App\Services\AI\WorkReportDecision;
use App\Services\AI\WorkReportSnapshot;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class PersonalWeeklyReportTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $owner;

    private User $alice;

    private User $bob;

    private int $aliceReport;

    private int $bobReport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        CarbonImmutable::setTestNow('2026-11-09 02:00:00 UTC');
        Http::preventStrayRequests();
        config(['work_report_ai.enabled' => true, 'work_report_ai.driver' => 'fake',
            'work_report_ai.use_daily_documents' => false, 'work_report_ai.queue_connection' => 'sync']);
        $this->owner = $this->member('OWNER');
        $this->alice = $this->member('OFFICE_STAFF');
        $this->bob = $this->member('OFFICE_STAFF');
        $this->aliceReport = $this->report($this->alice, 'Công việc riêng Alice');
        $this->bobReport = $this->report($this->bob, 'BÍ MẬT CỦA BOB');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function member(string $role, string $org = 'KPS'): User
    {
        $user = User::factory()->create();
        $member = $this->createMembership($user, organizationCode: $org);
        $this->attachRole($member['membership_id'], $this->createRole($role), true);

        return $user;
    }

    private function report(User $user, string $content, string $date = '2026-11-04'): int
    {
        $id = DB::table('daily_reports')->insertGetId([
            'organization_id' => $user->defaultOrganizationMembership->organization_id, 'user_id' => $user->id,
            'report_date' => $date, 'locked_at' => $date.' 16:00:00', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        DB::table('daily_report_items')->insert(['daily_report_id' => $id, 'content' => $content,
            'result' => 'Đã tạo 2 ảnh', 'status' => 'COMPLETED', 'sort_order' => 1,
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);

        return $id;
    }

    private function createAnalysis(string $end = '2026-11-07', int $days = 7): WorkReportAnalysis
    {
        return app(WorkReportAnalysisService::class)->request($this->owner, $end, $days, null)->refresh();
    }

    private function read(User $viewer, string $query = '')
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($viewer, 'web')->getJson('/api/reports/my-weekly-summary?end_date=2026-11-07'.$query);
    }

    public function test_staff_reads_only_their_existing_result_without_ai_permission_or_side_effects(): void
    {
        $run = $this->createAnalysis();
        $expected = app(WorkReportAnalysisService::class)->payload($this->owner, $run);
        $employee = collect($expected['employees'])->firstWhere('employee_id', $this->alice->id);
        $counts = [DB::table('work_report_analyses')->count(), DB::table('work_report_analysis_units')->count(), DB::table('mail_messages')->count()];
        Queue::fake();
        foreach (['', '&employee_id='.$this->bob->id.'&analysis_id='.$run->id.'&organization_id=999'] as $query) {
            $response = $this->read($this->alice, $query)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.employee.employee_id', $this->alice->id)
                ->assertJsonPath('data.employee.result', $employee['result'])->assertJsonPath('data.employee.work_groups', $employee['work_groups'])
                ->assertJsonPath('data.employee.source_report_ids', [$this->aliceReport])->assertJsonPath('data.source_changed', false);
            $body = $response->getContent();
            foreach (['BÍ MẬT CỦA BOB', $this->bob->email, 'review_feedback', 'scope_hash', 'snapshot', 'requested_by', 'usage', 'can_retry'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
            $this->assertSame([$this->aliceReport], array_column($response->json('data.source_reports'), 'id'));
        }
        $this->getJson('/api/ai/work-report-analyses/'.$run->id)->assertForbidden();
        $this->assertSame($counts, [DB::table('work_report_analyses')->count(), DB::table('work_report_analysis_units')->count(), DB::table('mail_messages')->count()]);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_weekly_web_and_word_agree_and_exports_obey_the_scope_and_revision(): void
    {
        DB::table('daily_report_items')->where('daily_report_id', $this->aliceReport)->update(['content' => 'Doanh thu trong ngày', 'result' => '800.000 đồng']);
        $next = $this->report($this->alice, 'Doanh số', '2026-11-05');
        DB::table('daily_report_items')->where('daily_report_id', $next)->update(['result' => '1.200.000 đồng']);
        DB::table('daily_report_items')->where('daily_report_id', $this->bobReport)->update(['result' => 'Doanh thu 500.000 đồng']);
        $admin = $this->member('ADMIN');
        $assistant = $this->member('EXECUTIVE_ASSISTANT');
        $run = $this->createAnalysis();
        $base = '/api/ai/work-report-analyses/'.$run->id;
        $self = '/api/reports/my-weekly-summary/word?end_date=2026-11-07';
        $runPayload = $this->actingAs($admin, 'web')->getJson($base)->assertOk()
            ->assertJsonMissingPath('data.weekly_revenue')->assertJsonPath('data.can_export_word', true);
        $byEmployee = collect($runPayload->json('data.employees'))->keyBy('employee_id');
        $this->assertSame(2000000, $byEmployee[$this->alice->id]['weekly_revenue']['amount']);
        $this->assertSame(500000, $byEmployee[$this->bob->id]['weekly_revenue']['amount']);
        $mine = $this->read($this->alice)->assertOk()->assertJsonPath('data.employee.weekly_revenue.amount', 2000000);
        $this->assertSame($byEmployee[$this->alice->id]['result'], $mine->json('data.employee.result'));
        $this->getJson($base.'/word?mode=full')->assertForbidden();
        $this->getJson($self.'&version='.str_repeat('a', 64))->assertConflict();
        $personal = $this->wordText($this->get($self.'&version='.$mine->json('data.export_version').'&employee_id='.$this->bob->id));
        $this->assertStringContainsString('2.000.000 đồng', $personal);
        $this->assertStringNotContainsString('BÍ MẬT CỦA BOB', $personal);
        $this->assertStringNotContainsString('2.500.000', $personal);
        foreach ([$assistant, $admin] as $viewer) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($viewer, 'web');
            $full = $this->wordText($this->get($base.'/word?mode=full'));
            $this->assertStringNotContainsString('2.500.000 đồng', $full);
            $this->assertStringContainsString('2.000.000 đồng', $full);
            $one = $this->wordText($this->get($base.'/word?mode=employee&employee_id='.$this->alice->id));
            $this->assertStringContainsString('2.000.000 đồng', $one);
            $this->assertStringNotContainsString('BÍ MẬT CỦA BOB', $one);
            $summary = $this->wordText($this->get($base.'/word?mode=summary'));
            $this->assertStringNotContainsString('2.500.000 đồng', $summary);
            $this->assertStringNotContainsString('Doanh thu cá nhân trong tuần', $summary);
            $this->assertStringNotContainsString('Báo cáo tuần của ', $summary);
            $this->getJson($base.'/word?mode=employee&employee_id=999999')->assertNotFound();
        }
        $daily = $this->createAnalysis('2026-11-04', 1);
        $this->actingAs($admin, 'web')->getJson('/api/ai/work-report-analyses/'.$daily->id)->assertOk()
            ->assertJsonMissingPath('data.weekly_revenue');
        $this->getJson('/api/ai/work-report-analyses/'.$daily->id.'/word?mode=full')->assertUnprocessable();
    }

    private function wordXmlParts($response, string ...$parts): array
    {
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        try {
            $documents = [];
            foreach ($parts as $part) {
                $xml = $zip->getFromName($part);
                $this->assertNotFalse($xml, "Missing Word part: $part");
                $document = new \DOMDocument;
                $this->assertTrue($document->loadXML($xml));
                $documents[] = $document;
            }

            return $documents;
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    private function wordDocument($response): \DOMDocument
    {
        return $this->wordXmlParts($response, 'word/document.xml')[0];
    }

    private function wordText($response): string
    {
        $document = $this->wordDocument($response);
        foreach ($document->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'br') as $break) {
            $break->parentNode->insertBefore($document->createTextNode("\n"), $break);
        }

        return $document->textContent;
    }

    public function test_word_starts_each_employee_on_a_new_page_without_a_leading_or_standalone_page_break(): void
    {
        $admin = $this->member('ADMIN');
        $run = $this->createAnalysis();
        $base = '/api/ai/work-report-analyses/'.$run->id;
        $people = $this->actingAs($admin, 'web')->getJson($base)->assertOk()->json('data.employees');
        $employeeTitles = array_map(fn ($employee) => 'Báo cáo tuần của '.$employee['name'], $people);
        $this->assertGreaterThanOrEqual(2, count($employeeTitles));
        foreach ([
            'mode=full' => ['Tổng kết công việc tuần', ...$employeeTitles],
            'mode=employee&employee_id='.$this->alice->id => ['Báo cáo tuần của '.$this->alice->name],
            'mode=summary' => ['Tổng kết công việc tuần'],
        ] as $query => $titles) {
            $xpath = new \DOMXPath($this->wordDocument($this->get($base.'/word?'.$query)));
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $brands = $xpath->query('/w:document/w:body/w:p[w:pPr/w:pStyle[@w:val="Brand"]]');
            $this->assertCount(count($titles), $brands);
            $this->assertSame(0, (int) $xpath->evaluate('count(//w:br[@w:type="page"])'));
            $this->assertSame(count($titles) - 1, (int) $xpath->evaluate('count(//w:pageBreakBefore)'));
            foreach ($brands as $index => $brand) {
                $this->assertSame($titles[$index], $xpath->evaluate('string(following-sibling::*[1][self::w:p])', $brand));
                $this->assertSame($index > 0, $xpath->evaluate('boolean(w:pPr/w:pageBreakBefore[not(@w:val="0" or @w:val="false")])', $brand));
            }
            $this->assertSame(0, (int) $xpath->evaluate('count(//w:p[w:pPr/w:pStyle[@w:val="H2"] and .="Căn cứ"])'));
        }
    }

    public function test_printed_claims_and_decision_fields_are_bulleted_and_each_reports_decisions_start_at_one(): void
    {
        $admin = $this->member('ADMIN');
        $run = $this->createAnalysis();
        $employee = $run->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->firstOrFail();
        $evidence = $employee->result['work_groups'][0]['evidence'];
        foreach (['Công ty' => $run, 'Cá nhân' => $employee] as $scope => $model) {
            $saved = $model->result;
            $saved['overview'] = [['text' => $scope.' — Kết quả <A> & B.', 'evidence' => $evidence]];
            $saved['recommendations'] = [];
            foreach (['Đối chiếu bản đầu.', 'Chốt bản sau.'] as $text) {
                $proposal = WorkReportDecision::fake(['text' => $scope.' — '.$text, 'evidence' => $evidence]);
                $proposal['action'] = $scope.' — Hành động cho '.$text;
                $saved['recommendations'][] = $proposal;
            }
            $model->update(['result' => $saved]);
        }
        $response = $this->actingAs($admin, 'web')->get('/api/ai/work-report-analyses/'.$run->id.'/word?mode=full');
        [$document, $numbering, $relationships, $types] = $this->wordXmlParts($response, 'word/document.xml', 'word/numbering.xml', 'word/_rels/document.xml.rels', '[Content_Types].xml');
        $this->assertSame('numbering.xml', (new \DOMXPath($relationships))->evaluate('string(//*[local-name()="Relationship" and @Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering"]/@Target)'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml', (new \DOMXPath($types))->evaluate('string(//*[local-name()="Override" and @PartName="/word/numbering.xml"]/@ContentType)'));

        $body = new \DOMXPath($document);
        $definitions = new \DOMXPath($numbering);
        $namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $body->registerNamespace('w', $namespace);
        $definitions->registerNamespace('w', $namespace);
        $lists = [];
        foreach ($body->query('//w:p[w:pPr/w:numPr]') as $paragraph) {
            $id = $body->evaluate('string(w:pPr/w:numPr/w:numId/@w:val)', $paragraph);
            $level = $body->evaluate('string(w:pPr/w:numPr/w:ilvl/@w:val)', $paragraph);
            $num = "/w:numbering/w:num[@w:numId='$id']";
            $abstract = $definitions->evaluate("string($num/w:abstractNumId/@w:val)");
            $style = "/w:numbering/w:abstractNum[@w:abstractNumId='$abstract']/w:lvl[@w:ilvl='$level']";
            $restart = $definitions->evaluate("string($num/w:lvlOverride[@w:ilvl='$level']/w:startOverride/@w:val)");
            $lists[$paragraph->textContent] = [
                'id' => $id, 'level' => (int) $level,
                'format' => $definitions->evaluate("string($style/w:numFmt/@w:val)"),
                'marker' => $definitions->evaluate("string($style/w:lvlText/@w:val)"),
                'start' => $restart === '' ? $definitions->evaluate("string($style/w:start/@w:val)") : $restart,
            ];
        }
        foreach (['Công ty', 'Cá nhân'] as $scope) {
            $claim = $lists[$scope.' — Kết quả <A> & B.'];
            $first = $lists[$scope.' — Đối chiếu bản đầu.'];
            $last = $lists[$scope.' — Chốt bản sau.'];
            $field = $lists['Hành động đề xuất · '.$scope.' — Hành động cho Đối chiếu bản đầu.'];
            $this->assertSame('bullet', $claim['format']);
            $this->assertNotSame('', trim($claim['marker']));
            $this->assertSame('bullet', $field['format']);
            $this->assertNotSame('', trim($field['marker']));
            $this->assertGreaterThan($claim['level'], $field['level']);
            $this->assertSame('decimal', $first['format']);
            $this->assertStringContainsString('%1', $first['marker']);
            $this->assertSame('1', $first['start']);
            $this->assertSame($first['id'], $last['id']);
        }
        $this->assertNotSame($lists['Công ty — Đối chiếu bản đầu.']['id'], $lists['Cá nhân — Đối chiếu bản đầu.']['id']);
    }

    public function test_word_preserves_saved_ai_claims_groups_metrics_and_decisions_in_order(): void
    {
        $quotedSource = 'Công việc <A> & "kiểm tra"';
        DB::table('daily_report_items')->where('daily_report_id', $this->aliceReport)->update(['content' => $quotedSource]);
        $admin = $this->member('ADMIN');
        $run = $this->createAnalysis();
        $unit = $run->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->firstOrFail();
        $saved = $unit->result;
        $evidence = $saved['work_groups'][0]['evidence'];
        $groupText = "Đã xuất bản bộ ảnh <B> & video.\nĐang đối chiếu  bản cuối.";
        $saved['work_groups'][0]['title'] = 'Nhóm việc đã tổng hợp';
        $saved['work_groups'][0]['text'] = $groupText;
        $claim = fn ($text) => ['text' => $text, 'evidence' => $evidence];
        $overview = 'Ghi nhận từ nguồn: Doanh thu ngày 04/11 là 830.000 đồng. Giữ nguyên nội dung đã ghi.';
        $saved['overview'] = [$claim($overview)];
        $saved['highlights'] = array_map(fn ($i) => $claim('Đầu ra số '.$i.'. Phần tiếp theo của đầu ra '.$i.'.'), range(1, 8));
        $saved['highlights'][] = $saved['highlights'][7];
        $saved['ongoing'] = [$claim('Hai bước tiếp theo vẫn đang được thực hiện.')];
        $saved['attention'] = [$claim('Điểm cần theo dõi riêng của nhân viên.')];
        $saved['support'] = [$claim('Cần phản hồi của nhóm phụ trách.')];
        $saved['uncertainties'] = [$claim('Chưa có xác nhận cho phiên bản hai.')];
        $decision = WorkReportDecision::fake($claim('Đề xuất: Giữ đề xuất đầu tiên.'));
        $decision['action'] = $decision['text'];
        $decision['decision_needed'] = 'Xác nhận phạm vi trước khi chuyển bước.';
        $saved['recommendations'] = [$decision, [...$decision, 'text' => 'Đề xuất ưu tiên cao được ghi sau.', 'priority' => 'high', 'action' => 'Làm rõ phương án sau.']];
        $observation = [...$evidence[0], 'quantity' => 2, 'quote' => 'Đã tạo 2 ảnh', 'unit' => 'ảnh', 'basis' => 'incremental'];
        $saved['quantity_observations'] = [
            [...$observation, 'metric' => 'Ảnh đăng trên kênh thử'],
            [...$observation, 'metric' => 'Ảnh chưa rõ phạm vi', 'basis' => 'unclear'],
        ];
        $unit->update(['result' => $saved]);

        $company = $run->result;
        $company['overview'] = [$claim('Đoạn mở đầu công ty chứa doanh thu ngày 04/11 là 830.000 đồng.')];
        $company['highlights'] = [$claim('Kết quả trong tuần của toàn công ty.')];
        $company['support'] = [$claim('Giữ mục hỗ trợ của toàn công ty.')];
        $run->update(['result' => $company]);
        $base = '/api/ai/work-report-analyses/'.$run->id;
        $web = $this->actingAs($admin, 'web')->getJson($base)->assertOk()->assertJsonPath('data.result', $company);
        $person = collect($web->json('data.employees'))->firstWhere('employee_id', $this->alice->id);
        $mine = $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'completed');
        foreach (['overview', 'highlights', 'ongoing', 'attention', 'support', 'uncertainties', 'recommendations'] as $section) {
            $this->assertSame($saved[$section], $person['result'][$section]);
            $this->assertSame($saved[$section], $mine->json('data.employee.result.'.$section));
        }
        $this->assertSame($saved['work_groups'], $mine->json('data.employee.work_groups'));
        $this->assertSame($person['work_totals'], $mine->json('data.employee.work_totals'));
        $this->assertSame(['Ảnh đăng trên kênh thử' => 2, 'Ảnh chưa rõ phạm vi' => null], array_column($person['work_totals'], 'total', 'metric'));
        $word = $this->wordText($this->get('/api/reports/my-weekly-summary/word?end_date=2026-11-07&version='.$mine->json('data.export_version')));
        $cursor = 0;
        $expected = [
            'Tổng hợp từ báo cáo', $saved['work_groups'][0]['title'], $groupText, 'Ảnh đăng trên kênh thử', '2 ảnh',
            'Cần làm rõ', 'Ảnh chưa rõ phạm vi', $saved['uncertainties'][0]['text'], 'Nhận xét', $overview,
            ...array_column($saved['highlights'], 'text'), $saved['ongoing'][0]['text'],
            $saved['attention'][0]['text'], $saved['support'][0]['text'], '4. Việc cần xem xét và quyết định', ...array_column($saved['recommendations'], 'text'),
        ];
        foreach ($expected as $text) {
            $offset = strpos($word, $text, $cursor);
            $this->assertNotFalse($offset, 'Missing or out-of-order saved text: '.$text);
            $cursor = $offset + strlen($text);
        }
        $this->assertSame(2, substr_count($word, $decision['text']));
        $this->assertStringContainsString('Ưu tiên cao', $word);
        $this->assertStringContainsString('Ưu tiên bình thường', $word);
        $sourceRef = 'Báo cáo #'.$this->aliceReport.' · Đầu việc #'.$evidence[0]['item_id'];
        $this->assertStringNotContainsString($sourceRef, $word);
        $this->assertStringNotContainsString($quotedSource, $word);
        $this->assertStringContainsString('Căn cứ · '.$decision['rationale'], $word);
        $this->assertStringNotContainsString('Báo cáo #'.$this->bobReport.' · Đầu việc #', $word);
        foreach (WorkReportDecision::FIELDS as $field) {
            $this->assertStringContainsString($decision[$field], $word);
        }
        $this->assertStringNotContainsString($company['overview'][0]['text'], $word);
        $this->assertStringNotContainsString('BÍ MẬT CỦA BOB', $word);
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'web');
        $summary = $this->wordText($this->get($base.'/word?mode=summary'));
        $this->assertStringContainsString($company['overview'][0]['text'], $summary);
        $this->assertStringContainsString($company['support'][0]['text'], $summary);
        $this->assertStringNotContainsString($sourceRef, $summary);
        $this->assertStringNotContainsString($quotedSource, $summary);
        $this->assertStringNotContainsString($groupText, $summary);
        $this->assertStringNotContainsString($overview, $summary);
        $this->assertSame($saved, $unit->fresh()->result);
        $this->assertSame($company, $run->fresh()->result);
    }

    public function test_owner_also_receives_only_their_own_work(): void
    {
        $own = $this->report($this->owner, 'Công việc của owner');
        $this->createAnalysis();
        $response = $this->read($this->owner)->assertOk()->assertJsonPath('data.employee.source_report_ids', [$own]);
        $this->assertStringNotContainsString('Công việc riêng Alice', $response->getContent());
        $this->assertStringNotContainsString('BÍ MẬT CỦA BOB', $response->getContent());
    }

    public function test_authentication_membership_and_report_permission_are_required(): void
    {
        $this->getJson('/api/reports/my-weekly-summary?end_date=2026-11-07')->assertUnauthorized();
        $this->read($this->member('OFFICE_STAFF', 'OTHER'))->assertUnauthorized();
        DB::table('organization_memberships')->where('user_id', $this->alice->id)->update(['is_active' => false]);
        $this->read($this->alice)->assertUnauthorized();
        $this->read($this->member('NO_PERMISSIONS'))->assertForbidden();
    }

    public function test_does_not_expose_foreign_department_or_other_period_analyses(): void
    {
        $run = $this->createAnalysis();
        $snapshot = $run->snapshot;
        $snapshot['scope']['restrictions'] = [['user', $this->alice->id]];
        $run->update(['scope_hash' => WorkReportSnapshot::hash($snapshot['scope']), 'snapshot' => $snapshot]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'not_analyzed')->assertJsonPath('data.employee', null);
        $department = DB::table('departments')->insertGetId(['organization_id' => $run->organization_id, 'code' => 'MKT', 'name' => 'Marketing', 'is_active' => true]);
        $run->update(['scope_hash' => WorkReportSnapshot::hash(['organization_id' => $run->organization_id, 'restrictions' => [['organization', $run->organization_id]]]), 'department_id' => $department]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.employee', null);
        $run->update(['department_id' => null, 'days' => 1]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.employee', null);
    }

    public function test_completed_personal_units_are_available_even_when_company_summary_fails(): void
    {
        $run = $this->createAnalysis();
        $run->units()->where('unit_key', 'summary')->update(['status' => 'failed', 'error_code' => 'AI_INVALID_EVIDENCE']);
        $run->update(['status' => 'partial']);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'completed');
        $run->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->update(['status' => 'failed', 'result' => null]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.employee.result', null);
    }

    public function test_cross_person_evidence_is_rejected_without_returning_the_bad_text(): void
    {
        $run = $this->createAnalysis();
        $other = $run->units()->where('unit_key', 'like', 'employee:'.$this->bob->id.':%')->firstOrFail();
        $run->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->firstOrFail()->update(['result' => $other->result]);
        $response = $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.employee.result', null);
        $this->assertStringNotContainsString('BÍ MẬT CỦA BOB', $response->getContent());
    }

    public function test_partial_and_running_parts_do_not_claim_a_complete_personal_week(): void
    {
        $run = $this->createAnalysis();
        $unit = $run->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->firstOrFail();
        $extra = $run->units()->create(['unit_key' => 'employee:'.$this->alice->id.':99', 'input' => $unit->input, 'status' => 'failed']);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.employee.completed_units', 1)->assertJsonPath('data.employee.total_units', 2)
            ->assertJsonPath('data.employee.failed_units', 1);
        $extra->update(['status' => 'pending']);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.employee.completed_units', 1);
    }

    public function test_current_tenant_and_source_permission_changes_are_enforced_on_every_read(): void
    {
        $run = $this->createAnalysis();
        $other = $this->createMembership(User::factory()->create(), organizationCode: 'OTHER');
        $run->update(['organization_id' => $other['organization_id']]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.employee', null);
        $run->update(['organization_id' => $this->alice->defaultOrganizationMembership->organization_id]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'completed');
        DB::table('role_permissions')->where('role_id', DB::table('roles')->where('code', 'OFFICE_STAFF')->value('id'))
            ->where('permission_id', DB::table('permissions')->where('code', 'reports.view')->value('id'))->delete();
        $this->read($this->alice)->assertForbidden();
    }

    public function test_old_completed_revision_remains_visible_while_new_one_is_running(): void
    {
        $old = $this->createAnalysis();
        $this->travel(1)->minutes();
        $makeup = $this->report($this->alice, 'Báo cáo bù mới', '2026-11-06');
        DB::table('daily_reports')->where('id', $makeup)->update(['is_makeup' => true]);
        Queue::fake();
        $new = $this->createAnalysis();
        $this->assertNotSame($old->id, $new->id);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.updating', true)
            ->assertJsonPath('data.source_changed', true)->assertJsonPath('data.employee.source_report_ids', [$this->aliceReport]);
        // Once that employee's new part completes, it becomes readable without waiting for the company summary.
        $worker = app(WorkReportAnalysisWorker::class);
        foreach ($new->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->get() as $unit) {
            $worker->process($unit->id);
        }
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.updating', false)
            ->assertJsonPath('data.employee.source_report_ids', [$this->aliceReport, $makeup]);
    }

    public function test_automation_revision_is_preferred_and_manual_company_history_is_the_fallback(): void
    {
        $auto = $this->createAnalysis();
        config(['work_report_ai.gateway.model' => 'next-model']);
        $manual = $this->createAnalysis();
        $this->assertNotSame($auto->id, $manual->id);
        $auto->update(['captured_at' => now()->subMinute()]);
        ReportAutomationCycle::query()->create(['organization_id' => $auto->organization_id, 'actor_user_id' => $this->owner->id, 'kind' => 'weekly',
            'end_date' => '2026-11-07', 'due_at' => now(), 'source_closes_at' => now(), 'mail_due_at' => now(), 'next_check_at' => now(),
            'analysis_id' => $auto->id]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.captured_at', $auto->captured_at->toIso8601String());
        $auto->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->update(['status' => 'failed', 'result' => null]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.captured_at', $manual->captured_at->toIso8601String());
    }

    public function test_new_makeup_and_item_changes_are_detected_only_for_the_signed_in_user(): void
    {
        $this->createAnalysis();
        $this->travel(1)->minutes();
        DB::table('daily_report_items')->where('daily_report_id', $this->bobReport)->update(['updated_at' => now()]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.source_changed', false);
        DB::table('daily_report_items')->where('daily_report_id', $this->aliceReport)->update(['updated_at' => now()]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.source_changed', true);
    }

    public function test_source_ownership_is_rechecked_and_invalid_week_queries_are_rejected(): void
    {
        $this->createAnalysis();
        DB::table('daily_reports')->where('id', $this->aliceReport)->update(['user_id' => $this->owner->id]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.employee', null)->assertJsonPath('data.status', 'no_data');
        foreach (['2026-11-21', '2026-02-30', 'invalid'] as $date) {
            $this->getJson('/api/reports/my-weekly-summary?end_date='.$date)->assertUnprocessable()->assertJsonValidationErrors('end_date');
        }
    }

    public function test_saved_sunday_week_is_discoverable_and_readable_without_relabeling_the_period(): void
    {
        $this->createAnalysis('2026-11-07');
        $this->createAnalysis('2026-11-08');
        Queue::fake();
        $counts = [WorkReportAnalysis::count(), DB::table('work_report_analysis_units')->count(), DB::table('mail_messages')->count()];
        $this->actingAs($this->alice, 'web')->getJson('/api/reports/my-weekly-summary/periods?employee_id='.$this->bob->id)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['data' => [
                ['start_date' => '2026-11-02', 'end_date' => '2026-11-08', 'days' => 7, 'timezone' => 'Asia/Ho_Chi_Minh'],
                ['start_date' => '2026-11-01', 'end_date' => '2026-11-07', 'days' => 7, 'timezone' => 'Asia/Ho_Chi_Minh'],
            ]]);
        $this->getJson('/api/reports/my-weekly-summary?end_date=2026-11-08')->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.period.start_date', '2026-11-02')
            ->assertJsonPath('data.period.end_date', '2026-11-08')->assertJsonPath('data.employee.source_report_ids', [$this->aliceReport]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.period.end_date', '2026-11-07');
        config(['work_report_ai.enabled' => false]);
        $this->getJson('/api/reports/my-weekly-summary/periods')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($counts, [WorkReportAnalysis::count(), DB::table('work_report_analysis_units')->count(), DB::table('mail_messages')->count()]);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_period_discovery_deduplicates_revisions_and_excludes_unrelated_tenants_scopes_and_sources(): void
    {
        $first = $this->createAnalysis('2026-11-08');
        config(['work_report_ai.gateway.model' => 'another-version']);
        $second = $this->createAnalysis('2026-11-08');
        $this->actingAs($this->alice, 'web')->getJson('/api/reports/my-weekly-summary/periods')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner, 'web')->getJson('/api/reports/my-weekly-summary/periods')
            ->assertOk()->assertExactJson(['data' => []]);
        $other = $this->createMembership(User::factory()->create(), organizationCode: 'OTHER');
        $first->update(['organization_id' => $other['organization_id']]);
        $second->update(['scope_hash' => str_repeat('0', 64)]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->alice, 'web')->getJson('/api/reports/my-weekly-summary/periods')->assertOk()->assertExactJson(['data' => []]);
        $first->update(['organization_id' => $this->alice->defaultOrganizationMembership->organization_id]);
        DB::table('daily_reports')->where('id', $this->aliceReport)->update(['user_id' => $this->owner->id]);
        $this->getJson('/api/reports/my-weekly-summary/periods')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_period_discovery_requires_active_membership_and_reports_permission(): void
    {
        $this->getJson('/api/reports/my-weekly-summary/periods')->assertUnauthorized();
        $this->actingAs($this->member('NO_PERMISSIONS'), 'web')->getJson('/api/reports/my-weekly-summary/periods')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->member('OFFICE_STAFF', 'OTHER'), 'web')->getJson('/api/reports/my-weekly-summary/periods')->assertUnauthorized();
    }

    public function test_period_discovery_does_not_offer_nonweekly_or_malformed_saved_ranges(): void
    {
        $run = $this->createAnalysis('2026-11-08');
        $this->actingAs($this->alice, 'web');
        $run->update(['days' => 1]);
        $this->getJson('/api/reports/my-weekly-summary/periods')->assertOk()->assertExactJson(['data' => []]);
        $run->update(['days' => 7, 'start_date' => '2026-11-01']);
        $this->getJson('/api/reports/my-weekly-summary/periods')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_empty_unanalyzed_and_disabled_ai_still_allow_reading_saved_results(): void
    {
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'not_analyzed');
        $this->read($this->owner)->assertOk()->assertJsonPath('data.status', 'no_data');
        $this->createAnalysis();
        config(['work_report_ai.enabled' => false]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'completed');
    }
}
