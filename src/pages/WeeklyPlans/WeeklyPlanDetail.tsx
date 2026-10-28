import ComponentCard from "@/components/common/ComponentCard";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import TextArea from "@/components/form/input/TextArea";
import WeeklyPlanEntries from "@/components/weekly-plans/WeeklyPlanEntries";
import WeeklyPlanEntryForm from "@/components/weekly-plans/WeeklyPlanEntryForm";
import GoogleDocsEmployeeMappingPanel from "@/components/weekly-plans/GoogleDocsEmployeeMappingPanel";
import WeeklyPlanMappingPanel from "@/components/weekly-plans/WeeklyPlanMappingPanel";
import WeeklyPlanSourceDocument from "@/components/weekly-plans/WeeklyPlanSourceDocument";
import WeeklyPlanSourceRecovery from "@/components/weekly-plans/WeeklyPlanSourceRecovery";
import WeeklyPlanStatusBadge from "@/components/weekly-plans/WeeklyPlanStatusBadge";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { ApiServiceError, type ValidationErrors } from "@/services/apiClient";
import {
  addWeeklyPlanComment,
  addWeeklyPlanEntry,
  analyzeWeeklyPlan,
  applyWeeklyPlanSourceEntries,
  assignWeeklyPlanSourceGroup,
  approveAndPublishWeeklyPlan,
  decideWeeklyPlanSuggestion,
  deleteWeeklyPlanEntry,
  extractWeeklyPlanSourceWithAi,
  getWeeklyPlan,
  getWeeklyPlanGoogleDocsEmployeeMappings,
  getWeeklyPlanReferences,
  extractWeeklyPlanSource,
  reparseWeeklyPlan,
  replaceWeeklyPlanSource,
  reviewWeeklyPlanEmployee,
  submitWeeklyPlan,
  updateWeeklyPlanEntry,
} from "@/services/weeklyPlanningService";
import type {
  WeeklyPlan,
  WeeklyPlanEntry,
  WeeklyPlanEntryInput,
  WeeklyPlanGoogleDocsEmployeeMappings,
  WeeklyPlanReferences,
  WeeklyPlanSourceEntryInput,
} from "@/types/weeklyPlanning";
import { formatDateOnly } from "@/utils/dateOnly";
import { hasRemainingGoogleDocsTabs as hasRemainingTabs } from "@/utils/weeklyPlanSourceSelection";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useParams } from "react-router";

type Props = { reviewMode?: boolean };

export default function WeeklyPlanDetail({ reviewMode = false }: Props) {
  const { t } = useTranslation("common", { keyPrefix: "weeklyPlanning" });
  const { id } = useParams();
  const [plan, setPlan] = useState<WeeklyPlan | null>(null);
  const [googleDocsMappings, setGoogleDocsMappings] =
    useState<WeeklyPlanGoogleDocsEmployeeMappings | null>(null);
  const [references, setReferences] = useState<WeeklyPlanReferences | null>(
    null,
  );
  const [editing, setEditing] = useState<WeeklyPlanEntry | "new" | null>(null);
  const [comment, setComment] = useState("");
  const [commentTarget, setCommentTarget] = useState("plan");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [showAssignmentConfirm, setShowAssignmentConfirm] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [entryFormErrors, setEntryFormErrors] = useState<ValidationErrors>({});
  const [entryFormErrorVersion, setEntryFormErrorVersion] = useState(0);
  const [version, setVersion] = useState(0);

  useEffect(() => {
    if (!id) return;
    const controller = new AbortController();
    Promise.all([
      getWeeklyPlan(id, controller.signal),
      getWeeklyPlanReferences(controller.signal),
    ])
      .then(async ([nextPlan, nextReferences]) => {
        let nextMappings: WeeklyPlanGoogleDocsEmployeeMappings | null = null;
        if (
          nextPlan.source_type === "GOOGLE_DOCS" &&
          (nextPlan.actions.can_apply_source ||
            nextPlan.actions.can_manage_google_docs_mappings)
        ) {
          nextMappings = await getWeeklyPlanGoogleDocsEmployeeMappings(
            nextPlan.id,
            controller.signal,
          );
        }
        if (controller.signal.aborted) return;
        setPlan(nextPlan);
        setReferences(nextReferences);
        setGoogleDocsMappings(nextMappings);
        setError(null);
      })
      .catch((nextError: unknown) => {
        if (
          nextError instanceof DOMException &&
          nextError.name === "AbortError"
        )
          return;
        setError(
          nextError instanceof ApiServiceError && nextError.status === 403
            ? t("errors.forbidden")
            : nextError instanceof ApiServiceError && nextError.status === 404
              ? t("errors.notFound")
              : t("errors.loadDetail"),
        );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [id, t, version]);

  const perform = async (
    action: () => Promise<WeeklyPlan>,
    onValidationErrors?: (errors: ValidationErrors) => void,
  ): Promise<boolean> => {
    if (busy) return false;
    setBusy(true);
    setError(null);
    try {
      setPlan(await action());
      setEditing(null);
      return true;
    } catch (nextError) {
      const validation =
        nextError instanceof ApiServiceError
          ? Object.values(nextError.validationErrors ?? {})[0]?.[0]
          : undefined;
      if (nextError instanceof ApiServiceError && nextError.validationErrors) {
        onValidationErrors?.(nextError.validationErrors);
      }
      setError(
        validation ??
          (nextError instanceof ApiServiceError && nextError.status === 409
            ? nextError.message
            : t("errors.action")),
      );
      return false;
    } finally {
      setBusy(false);
    }
  };

  if (loading && !plan) {
    return (
      <>
        <PageMeta
          title={t("detail.metaTitle")}
          description={t("detail.metaDescription")}
        />
        <PageBreadCrumb pageTitle={t("detail.title")} />
        <div className="h-96 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800" />
      </>
    );
  }

  if (!plan || !references) {
    return (
      <>
        <PageMeta
          title={t("detail.metaTitle")}
          description={t("detail.metaDescription")}
        />
        <PageBreadCrumb pageTitle={t("detail.title")} />
        <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
          <p className="text-theme-sm text-error-600 dark:text-error-400">
            {error}
          </p>
          <Button
            type="button"
            variant="outline"
            className="mt-4"
            onClick={() => {
              setLoading(true);
              setVersion((current) => current + 1);
            }}
          >
            {t("actions.retry")}
          </Button>
        </section>
      </>
    );
  }

  const latestAnalysisEvent = [...plan.events]
    .reverse()
    .find((event) => event.type === "ANALYZED");
  const latestInvalidationEvent = [...plan.events]
    .reverse()
    .find((event) => event.type === "SUGGESTIONS_INVALIDATED");
  const analysisIsStale = Boolean(
    latestInvalidationEvent &&
    (!latestAnalysisEvent ||
      latestInvalidationEvent.id > latestAnalysisEvent.id),
  );
  const hasCurrentAnalysis = Boolean(latestAnalysisEvent) && !analysisIsStale;
  const hasRemainingGoogleDocsTabs =
    plan.source_type !== "GOOGLE_DOCS" ||
    hasRemainingTabs(
      plan.source_document.google_docs?.remaining_tab_ids ?? [],
    );

  return (
    <>
      <PageMeta
        title={reviewMode ? t("review.metaTitle") : t("detail.metaTitle")}
        description={t("detail.metaDescription")}
      />
      <PageBreadCrumb
        pageTitle={reviewMode ? t("review.title") : t("detail.title")}
      />

      <div className="-mt-4 mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <p className="font-semibold text-gray-900 dark:text-white">
              {t("detail.period", {
                start: formatDateOnly(plan.week_start),
                end: formatDateOnly(plan.week_end),
              })}
            </p>
            <WeeklyPlanStatusBadge status={plan.status} />
          </div>
          <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
            {t("detail.createdBy", { name: plan.creator.name })}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link
            to="/weekly-plans"
            className="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2.5 text-theme-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
          >
            {t("actions.back")}
          </Link>
          {!reviewMode && plan.actions.can_review && (
            <Link
              to={"/weekly-plans/" + plan.id + "/review"}
              className="inline-flex items-center rounded-lg bg-kps-primary px-4 py-2.5 text-theme-sm font-semibold text-white transition hover:bg-kps-primary-hover"
            >
              {t("detail.openReview")}
            </Link>
          )}
        </div>
      </div>

      {error && (
        <p
          role="alert"
          className="mb-5 rounded-lg bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
          {error}
        </p>
      )}

      <div className="space-y-5">
        <ComponentCard title={t("detail.fileTitle")} compact>
          <dl className="grid gap-4 text-theme-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("detail.fileName")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {plan.source_document.google_docs?.title ??
                  plan.source_file.name}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("detail.parseStatus")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {t("parseStatus." + plan.parse_status)}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("detail.entryCount")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {plan.entries.length}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {plan.source_type === "GOOGLE_DOCS"
                  ? t("detail.characterCount")
                  : t("detail.fileSize")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {plan.source_type === "GOOGLE_DOCS"
                  ? plan.source_document.google_docs?.character_count
                  : `${Math.ceil(plan.source_file.size / 1024)} KB`}
              </dd>
            </div>
          </dl>
          <dl className="grid gap-4 border-t border-gray-100 pt-4 text-theme-sm sm:grid-cols-2 lg:grid-cols-4 dark:border-gray-800">
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("detail.employeeCount")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {plan.summary.employee_count}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("detail.matchedEmployeeCount")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {plan.summary.matched_employee_count}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("detail.invalidEntryCount")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {plan.summary.invalid_entry_count}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("detail.departments")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {plan.summary.departments.length > 0
                  ? plan.summary.departments.join(", ")
                  : t("detail.noDepartment")}
              </dd>
            </div>
          </dl>
          {plan.parse_metadata?.errors?.map((message) => (
            <p
              key={message}
              className="rounded-lg bg-warning-50 px-3 py-2 text-theme-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400"
            >
              {message}
            </p>
          ))}
          <WeeklyPlanSourceRecovery
            key={`${plan.source_file.sha256}:${plan.source_file.availability}`}
            plan={plan}
            busy={busy}
            onReplace={(file) =>
              void perform(() => replaceWeeklyPlanSource(plan.id, file))
            }
          />
        </ComponentCard>

        {["docx", "gdoc"].includes(plan.source_file.extension) &&
          hasRemainingGoogleDocsTabs && (
          <WeeklyPlanSourceDocument
            plan={plan}
            employees={references.employees}
            googleDocsMappings={googleDocsMappings}
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
                () => applyWeeklyPlanSourceEntries(plan.id, requestId, entries),
                (errors) => {
                  validationErrors = errors;
                },
              );
              return { succeeded, validationErrors };
            }}
            onAssignGroup={async (passageIds, groupTitle) =>
              perform(() =>
                assignWeeklyPlanSourceGroup(plan.id, passageIds, groupTitle),
              )
            }
          />
        )}

        {plan.source_type === "GOOGLE_DOCS" &&
          hasRemainingGoogleDocsTabs &&
          plan.actions.can_manage_google_docs_mappings &&
          googleDocsMappings && (
            <GoogleDocsEmployeeMappingPanel
              key={plan.id}
              planId={plan.id}
              mappings={googleDocsMappings}
              onMappingsChange={setGoogleDocsMappings}
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

        <ComponentCard
          title={t("detail.entriesTitle")}
          desc={t("detail.entriesDescription")}
          compact
        >
          {plan.actions.can_edit && (
            <div className="flex justify-end">
              <Button
                type="button"
                className="bg-kps-primary hover:bg-kps-primary-hover"
                onClick={() => {
                  setEntryFormErrors({});
                  setEditing("new");
                }}
              >
                {t("detail.addEntry")}
              </Button>
            </div>
          )}
          <WeeklyPlanEntries
            entries={plan.entries}
            reviews={plan.employee_reviews}
            canEdit={plan.actions.can_edit}
            canReview={reviewMode && plan.actions.can_review}
            busy={busy}
            onEdit={(entry) => {
              setEntryFormErrors({});
              setEditing(entry);
            }}
            onDelete={(entry) => {
              if (window.confirm(t("confirm.deleteEntry"))) {
                void perform(() => deleteWeeklyPlanEntry(plan.id, entry.id));
              }
            }}
            onReview={(assigneeId, status) => {
              const reviewComment =
                status === "CHANGES_REQUESTED"
                  ? (window.prompt(t("review.changeCommentPrompt")) ?? "")
                  : undefined;
              if (status === "CHANGES_REQUESTED" && !reviewComment) return;
              void perform(() =>
                reviewWeeklyPlanEmployee(
                  plan.id,
                  assigneeId,
                  status,
                  reviewComment,
                ),
              );
            }}
          />
        </ComponentCard>

        {(plan.actions.can_analyze ||
          hasCurrentAnalysis ||
          analysisIsStale) && (
          <ComponentCard
            title={t("detail.suggestionsTitle")}
            desc={t("detail.suggestionsDescription")}
            compact
          >
            <div className="space-y-3">
              {analysisIsStale && (
                <p className="rounded-lg bg-warning-50 px-4 py-3 text-theme-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                  {t("detail.analysisStale")}
                </p>
              )}
              {!hasCurrentAnalysis && !analysisIsStale && (
                <p className="text-theme-sm text-gray-500 dark:text-gray-400">
                  {t("detail.analysisNotRun")}
                </p>
              )}
              {hasCurrentAnalysis && plan.suggestions.length === 0 && (
                <p className="rounded-lg bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:bg-success-500/10 dark:text-success-400">
                  {t("detail.analysisEmpty")}
                </p>
              )}
              {plan.suggestions.map((suggestion) => (
                <article
                  key={suggestion.id}
                  className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
                >
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <p className="font-medium text-gray-900 dark:text-white">
                      {suggestion.suggested_value}
                    </p>
                    <span className="rounded-full bg-gray-100 px-2.5 py-1 text-theme-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                      {t("detail.suggestionStatus." + suggestion.status)}
                    </span>
                  </div>
                  <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                    {suggestion.reason}
                  </p>
                  {plan.actions.can_edit && suggestion.status === "PENDING" && (
                    <div className="mt-3 flex gap-2">
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={() =>
                          void perform(() =>
                            decideWeeklyPlanSuggestion(
                              plan.id,
                              suggestion.id,
                              "REJECTED",
                            ),
                          )
                        }
                      >
                        {t("detail.rejectSuggestion")}
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        disabled={busy}
                        className="bg-kps-primary hover:bg-kps-primary-hover"
                        onClick={() =>
                          void perform(() =>
                            decideWeeklyPlanSuggestion(
                              plan.id,
                              suggestion.id,
                              "ACCEPTED",
                            ),
                          )
                        }
                      >
                        {t("detail.acceptSuggestion")}
                      </Button>
                    </div>
                  )}
                </article>
              ))}
              {plan.actions.can_analyze && (
                <div className="flex justify-end">
                  <Button
                    type="button"
                    disabled={busy}
                    className="bg-kps-primary hover:bg-kps-primary-hover"
                    onClick={() =>
                      void perform(() => analyzeWeeklyPlan(plan.id))
                    }
                  >
                    {busy
                      ? t("detail.analyzing")
                      : hasCurrentAnalysis
                        ? t("detail.reanalyze")
                        : t("detail.analyze")}
                  </Button>
                </div>
              )}
            </div>
          </ComponentCard>
        )}

        <ComponentCard title={t("detail.commentsTitle")} compact>
          <div className="space-y-3">
            {plan.comments.map((item) => (
              <div
                key={item.id}
                className="rounded-xl bg-gray-50 p-3 dark:bg-gray-800/60"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-theme-sm font-semibold text-gray-900 dark:text-white">
                    {item.author.name}
                  </p>
                  {(item.assignee_id || item.entry_id) && (
                    <span className="rounded-full bg-gray-200 px-2 py-0.5 text-theme-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                      {item.entry_id
                        ? t("detail.commentForEntry", { id: item.entry_id })
                        : t("detail.commentForEmployee", {
                            name:
                              plan.employee_reviews.find(
                                (review) =>
                                  review.assignee.id === item.assignee_id,
                              )?.assignee.name ??
                              t("detail.unresolvedEmployee"),
                          })}
                    </span>
                  )}
                </div>
                <p className="mt-1 text-theme-sm whitespace-pre-wrap text-gray-600 dark:text-gray-300">
                  {item.content}
                </p>
              </div>
            ))}
            {plan.comments.length === 0 && (
              <p className="text-theme-sm text-gray-500 dark:text-gray-400">
                {t("detail.noComments")}
              </p>
            )}
            {plan.actions.can_comment && (
              <>
                <div>
                  <Label htmlFor="weekly-comment-target">
                    {t("detail.commentTarget")}
                  </Label>
                  <Select
                    id="weekly-comment-target"
                    value={commentTarget}
                    onChange={setCommentTarget}
                    options={[
                      { value: "plan", label: t("detail.commentWholePlan") },
                      ...plan.employee_reviews.map((review) => ({
                        value: "employee:" + review.assignee.id,
                        label: t("detail.commentEmployee", {
                          name: review.assignee.name,
                        }),
                      })),
                      ...plan.entries.map((entry) => ({
                        value: "entry:" + entry.id,
                        label: t("detail.commentEntry", {
                          id: entry.id,
                          title: entry.title,
                        }),
                      })),
                    ]}
                  />
                </div>
                <TextArea
                  rows={3}
                  value={comment}
                  placeholder={t("detail.commentPlaceholder")}
                  onChange={setComment}
                />
                <div className="flex justify-end">
                  <Button
                    type="button"
                    disabled={busy || !comment.trim()}
                    className="bg-kps-primary hover:bg-kps-primary-hover"
                    onClick={() =>
                      void perform(async () => {
                        const [targetType, targetId] = commentTarget.split(":");
                        const next = await addWeeklyPlanComment(
                          plan.id,
                          comment.trim(),
                          targetType === "employee"
                            ? Number(targetId)
                            : undefined,
                          targetType === "entry" ? Number(targetId) : undefined,
                        );
                        setComment("");
                        return next;
                      })
                    }
                  >
                    {t("detail.addComment")}
                  </Button>
                </div>
              </>
            )}
          </div>
        </ComponentCard>

        <ComponentCard title={t("detail.historyTitle")} compact>
          {plan.events.length > 0 ? (
            <ol className="space-y-3">
              {plan.events.map((event) => (
                <li
                  key={event.id}
                  className="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800/60"
                >
                  <p className="text-theme-sm font-medium text-gray-900 dark:text-white">
                    {t("events." + event.type, { defaultValue: event.type })}
                  </p>
                  <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    {event.actor?.name ?? t("detail.systemActor")} ·{" "}
                    {new Intl.DateTimeFormat("vi-VN", {
                      dateStyle: "short",
                      timeStyle: "short",
                      timeZone: "Asia/Ho_Chi_Minh",
                    }).format(new Date(event.created_at))}
                  </p>
                </li>
              ))}
            </ol>
          ) : (
            <p className="text-theme-sm text-gray-500 dark:text-gray-400">
              {t("detail.noHistory")}
            </p>
          )}
        </ComponentCard>

        <div className="flex flex-wrap justify-end gap-3 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
          {plan.actions.can_submit && (
            <Button
              type="button"
              disabled={busy}
              className="bg-kps-primary hover:bg-kps-primary-hover"
              onClick={() => {
                if (window.confirm(t("confirm.submit"))) {
                  void perform(() => submitWeeklyPlan(plan.id));
                }
              }}
            >
              {t("detail.submit")}
            </Button>
          )}
          {reviewMode && plan.actions.can_approve_and_publish && (
            <Button
              type="button"
              disabled={busy}
              className="bg-kps-primary hover:bg-kps-primary-hover"
              onClick={() => {
                setError(null);
                setShowAssignmentConfirm(true);
              }}
            >
              {t("review.approveAndPublish")}
            </Button>
          )}
        </div>
      </div>

      <Modal
        isOpen={editing !== null}
        onClose={() => {
          if (!busy) setEditing(null);
        }}
        className="max-h-[90vh] max-w-3xl overflow-y-auto p-6"
        ariaLabelledBy="weekly-entry-form-title"
      >
        <h2
          id="weekly-entry-form-title"
          className="mb-5 text-lg font-semibold text-gray-900 dark:text-white"
        >
          {editing === "new"
            ? t("entryForm.addTitle")
            : t("entryForm.editTitle")}
        </h2>
        <WeeklyPlanEntryForm
          key={editing === "new" ? "new" : editing?.id}
          entry={editing === "new" ? undefined : (editing ?? undefined)}
          employees={references.employees}
          weekStart={plan.week_start}
          weekEnd={plan.week_end}
          reviewerEdit={reviewMode && !plan.actions.can_edit}
          isSaving={busy}
          serverErrors={entryFormErrors}
          serverErrorVersion={entryFormErrorVersion}
          onCancel={() => setEditing(null)}
          onSave={(input: WeeklyPlanEntryInput) => {
            if (editing === "new") {
              void perform(
                () => addWeeklyPlanEntry(plan.id, input),
                (errors) => {
                  setEntryFormErrors(errors);
                  setEntryFormErrorVersion((current) => current + 1);
                },
              );
            } else if (editing) {
              void perform(
                () => updateWeeklyPlanEntry(plan.id, editing.id, input),
                (errors) => {
                  setEntryFormErrors(errors);
                  setEntryFormErrorVersion((current) => current + 1);
                },
              );
            }
          }}
        />
      </Modal>

      <Modal
        isOpen={showAssignmentConfirm}
        onClose={() => {
          if (!busy) setShowAssignmentConfirm(false);
        }}
        showCloseButton={false}
        className="max-w-lg p-6"
        ariaLabelledBy="weekly-approve-publish-title"
      >
        <h2
          id="weekly-approve-publish-title"
          className="text-lg font-semibold text-gray-900 dark:text-white"
        >
          {t("review.confirmTitle")}
        </h2>
        <p className="mt-3 text-theme-sm text-gray-600 dark:text-gray-300">
          {t("review.confirmDescription", {
            employees: plan.summary.employee_count,
            tasks: plan.summary.entry_count,
          })}
        </p>
        <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
          {t("review.confirmSafety")}
        </p>
        {error && (
          <p
            role="alert"
            className="mt-4 rounded-lg bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
          >
            {error}
          </p>
        )}
        <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            disabled={busy}
            onClick={() => setShowAssignmentConfirm(false)}
          >
            {t("actions.cancel")}
          </Button>
          <Button
            type="button"
            disabled={busy}
            className="bg-kps-primary hover:bg-kps-primary-hover"
            onClick={() => {
              void perform(() => approveAndPublishWeeklyPlan(plan.id)).then(
                (succeeded) => {
                  if (succeeded) setShowAssignmentConfirm(false);
                },
              );
            }}
          >
            {busy ? t("review.publishing") : t("review.approveAndPublish")}
          </Button>
        </div>
      </Modal>
    </>
  );
}
