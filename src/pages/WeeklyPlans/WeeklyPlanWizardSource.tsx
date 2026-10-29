import ComponentCard from "@/components/common/ComponentCard";
import WeeklyPlanMappingPanel from "@/components/weekly-plans/WeeklyPlanMappingPanel";
import WeeklyPlanSourceRecovery from "@/components/weekly-plans/WeeklyPlanSourceRecovery";
import WeeklyPlanWizardLayout from "@/components/weekly-plans/WeeklyPlanWizardLayout";
import GoogleDocsEmployeeMappingPanel from "@/components/weekly-plans/GoogleDocsEmployeeMappingPanel";
import Button from "@/components/ui/button/Button";
import { useWeeklyPlanWizard } from "@/context/WeeklyPlanWizardContext";
import {
  reparseWeeklyPlan,
  replaceWeeklyPlanSource,
} from "@/services/weeklyPlanningService";
import { weeklyPlanSourceReady } from "@/utils/weeklyPlanWizard";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

export default function WeeklyPlanWizardSource() {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.wizard",
  });
  const { plan, references, mappings, setMappings, busy, perform } =
    useWeeklyPlanWizard();
  const navigate = useNavigate();

  return (
    <WeeklyPlanWizardLayout step="source">
      {plan && references && (
        <>
          <ComponentCard
            title={t("source.title")}
            desc={t("source.description")}
          >
            <dl className="grid gap-4 text-theme-sm sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <dt className="text-gray-500 dark:text-gray-400">
                  {t("source.sourceName")}
                </dt>
                <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                  {plan.source_document.google_docs?.title ??
                    plan.source_file.name}
                </dd>
              </div>
              <div>
                <dt className="text-gray-500 dark:text-gray-400">
                  {t("source.state")}
                </dt>
                <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                  {plan.source_document.status}
                </dd>
              </div>
              <div>
                <dt className="text-gray-500 dark:text-gray-400">
                  {t("source.passages")}
                </dt>
                <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                  {plan.source_document.google_docs?.passage_count ??
                    plan.source_document.passages.length}
                </dd>
              </div>
              <div>
                <dt className="text-gray-500 dark:text-gray-400">
                  {t("source.processing")}
                </dt>
                <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                  {t("source.processedCount", {
                    processed:
                      plan.source_document.google_docs?.processed_tab_count ??
                      0,
                    relevant:
                      plan.source_document.google_docs?.relevant_tab_count ?? 0,
                  })}
                </dd>
              </div>
              {plan.source_document.google_docs && (
                <>
                  <div>
                    <dt className="text-gray-500 dark:text-gray-400">
                      {t("source.tabs")}
                    </dt>
                    <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                      {plan.source_document.google_docs.tab_count}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500 dark:text-gray-400">
                      {t("source.characters")}
                    </dt>
                    <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                      {plan.source_document.google_docs.character_count.toLocaleString(
                        "vi-VN",
                      )}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500 dark:text-gray-400">
                      {t("source.capturedAt")}
                    </dt>
                    <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                      {new Intl.DateTimeFormat("vi-VN", {
                        dateStyle: "short",
                        timeStyle: "short",
                        timeZone: "Asia/Ho_Chi_Minh",
                      }).format(
                        new Date(plan.source_document.google_docs.captured_at),
                      )}
                    </dd>
                  </div>
                </>
              )}
            </dl>
            <WeeklyPlanSourceRecovery
              plan={plan}
              busy={busy}
              onReplace={(file) =>
                void perform(() => replaceWeeklyPlanSource(plan.id, file))
              }
            />
          </ComponentCard>

          {plan.source_type === "GOOGLE_DOCS" && (
            <GoogleDocsEmployeeMappingPanel
              planId={plan.id}
              mappings={mappings}
              readOnlyTabIds={
                plan.source_document.google_docs?.processed_tab_ids ?? []
              }
              onMappingsChange={setMappings}
            />
          )}

          {plan.parse_status === "NEEDS_MAPPING" &&
            (plan.parse_metadata?.headers?.length ?? 0) > 0 &&
            plan.actions.can_edit && (
              <WeeklyPlanMappingPanel
                headers={plan.parse_metadata?.headers ?? []}
                isSaving={busy}
                onSubmit={(mapping) =>
                  void perform(() => reparseWeeklyPlan(plan.id, mapping))
                }
              />
            )}

          <div className="flex justify-end">
            <Button
              type="button"
              disabled={!weeklyPlanSourceReady(plan, mappings)}
              className="bg-kps-primary hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
              onClick={() => navigate(`/weekly-plans/${plan.id}/structure`)}
            >
              {t("source.continue")}
            </Button>
          </div>
        </>
      )}
    </WeeklyPlanWizardLayout>
  );
}
