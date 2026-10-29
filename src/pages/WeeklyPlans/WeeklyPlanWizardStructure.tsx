import ComponentCard from "@/components/common/ComponentCard";
import WeeklyPlanSourceDocument from "@/components/weekly-plans/WeeklyPlanSourceDocument";
import WeeklyPlanWizardLayout from "@/components/weekly-plans/WeeklyPlanWizardLayout";
import Button from "@/components/ui/button/Button";
import { useWeeklyPlanWizard } from "@/context/WeeklyPlanWizardContext";
import type { ValidationErrors } from "@/services/apiClient";
import {
  applyWeeklyPlanSourceEntries,
  assignWeeklyPlanSourceGroup,
  extractWeeklyPlanSource,
  extractWeeklyPlanSourceWithAi,
} from "@/services/weeklyPlanningService";
import type { WeeklyPlanSourceEntryInput } from "@/types/weeklyPlanning";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

export default function WeeklyPlanWizardStructure() {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.wizard",
  });
  const { plan, references, mappings, busy, perform } = useWeeklyPlanWizard();
  const navigate = useNavigate();

  return (
    <WeeklyPlanWizardLayout step="structure">
      {plan && references && (
        <>
          {plan.source_type === "GOOGLE_DOCS" &&
            plan.source_document.google_docs &&
            plan.source_document.google_docs.remaining_tab_ids.length > 0 && (
              <p className="rounded-xl bg-brand-50 px-4 py-3 text-theme-sm font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                {t("structure.progress", {
                  processed:
                    plan.source_document.google_docs.processed_tab_count,
                  relevant: plan.source_document.google_docs.relevant_tab_count,
                })}
              </p>
            )}
          {plan.source_type === "GOOGLE_DOCS" &&
          (plan.source_document.google_docs?.remaining_tab_ids.length ?? 0) ===
            0 ? (
            <ComponentCard
              title={t("structure.completeTitle")}
              desc={t("structure.completeDescription")}
            >
              <dl className="grid gap-4 text-theme-sm sm:grid-cols-3">
                <div>
                  <dt className="text-gray-500 dark:text-gray-400">
                    {t("counts.entries")}
                  </dt>
                  <dd className="mt-1 text-title-sm font-semibold text-gray-900 dark:text-white">
                    {plan.summary.entry_count}
                  </dd>
                </div>
                <div>
                  <dt className="text-gray-500 dark:text-gray-400">
                    {t("counts.items")}
                  </dt>
                  <dd className="mt-1 text-title-sm font-semibold text-gray-900 dark:text-white">
                    {plan.summary.item_count}
                  </dd>
                </div>
                <div>
                  <dt className="text-gray-500 dark:text-gray-400">
                    {t("counts.employees")}
                  </dt>
                  <dd className="mt-1 text-title-sm font-semibold text-gray-900 dark:text-white">
                    {plan.summary.matched_employee_count}
                  </dd>
                </div>
              </dl>
            </ComponentCard>
          ) : (
            <WeeklyPlanSourceDocument
              plan={plan}
              employees={references.employees}
              googleDocsMappings={mappings}
              busy={busy}
              onExtract={() =>
                void perform(() => extractWeeklyPlanSource(plan.id))
              }
              onAiExtract={(sourcePassageIds) =>
                extractWeeklyPlanSourceWithAi(plan.id, sourcePassageIds)
              }
              onApply={async (
                requestId: string,
                entries: WeeklyPlanSourceEntryInput[],
              ) => {
                let validationErrors: ValidationErrors | undefined;
                const succeeded = await perform(
                  () =>
                    applyWeeklyPlanSourceEntries(plan.id, requestId, entries),
                  (errors) => {
                    validationErrors = errors;
                  },
                );
                return { succeeded, validationErrors };
              }}
              onAssignGroup={(passageIds, groupTitle) =>
                perform(() =>
                  assignWeeklyPlanSourceGroup(plan.id, passageIds, groupTitle),
                )
              }
            />
          )}

          {plan.entries.length > 0 &&
            (plan.source_type !== "GOOGLE_DOCS" ||
              (plan.source_document.google_docs?.remaining_tab_ids.length ??
                0) === 0) && (
              <div className="flex justify-end">
                <Button
                  type="button"
                  onClick={() => navigate(`/weekly-plans/${plan.id}/work`)}
                >
                  {t("structure.continue")}
                </Button>
              </div>
            )}
        </>
      )}
    </WeeklyPlanWizardLayout>
  );
}
