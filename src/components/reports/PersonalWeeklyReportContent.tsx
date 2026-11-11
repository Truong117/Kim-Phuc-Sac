import WorkAnalysisResult from "@/components/ai/WorkAnalysisResult";
import WorkAnalysisSources from "@/components/ai/WorkAnalysisSources";
import WorkAnalysisWeeklyWork from "@/components/ai/WorkAnalysisWeeklyWork";
import { analysisSections } from "@/types/workReportAnalysis";
import type { PersonalWeeklyReport } from "@/types/personalWeeklyReport";

export default function PersonalWeeklyReportContent({
  report,
}: {
  report: PersonalWeeklyReport;
}) {
  const employee = report.employee;
  if (!employee) return null;
  return (
    <div className="space-y-6">
      {employee.result && (
        <>
          <WorkAnalysisWeeklyWork
            groups={employee.work_groups ?? []}
            totals={employee.work_totals ?? []}
            partial={report.status !== "completed"}
          />
          <WorkAnalysisResult
            result={employee.result}
            sources={report.source_reports}
            totals={employee.work_totals ?? []}
            employee
          />
        </>
      )}
      <WorkAnalysisSources
        sources={report.source_reports}
        inventory={employee.work_inventory ?? []}
        reportIds={employee.source_report_ids}
        evidence={[
          ...(employee.work_groups ?? []).flatMap((group) => group.evidence),
          ...(employee.work_totals ?? []).flatMap((total) => total.entries),
          ...(employee.result
            ? analysisSections.flatMap((section) =>
                employee.result![section].flatMap((claim) => claim.evidence),
              )
            : []),
        ]}
      />
    </div>
  );
}
