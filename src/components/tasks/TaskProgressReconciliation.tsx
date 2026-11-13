import { useEffect, useState } from "react";
import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import TaskOutputEditor from "./TaskOutputEditor";
import { ApiServiceError } from "@/services/apiClient";
import {
  getProgressSources,
  importProgressSources,
  type ProgressSource,
  type ProgressSources,
} from "@/services/taskProgressReconciliation";
import {
  addDaysToDateOnly,
  dateOnlyInTimeZone,
  formatDateOnly,
} from "@/utils/dateOnly";
import { needsOutputReference, taskOutputIdentity } from "@/utils/taskProgress";
import type { ManagedTask, TaskOutput, TaskQuantityUnit } from "@/types/tasks";

type Selection = { source: ProgressSource; outputs: TaskOutput[] };
export default function TaskProgressReconciliation({
  task,
  onImported,
}: {
  task: ManagedTask;
  onImported: () => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "taskProgress" });
  const yesterday = addDaysToDateOnly(dateOnlyInTimeZone(), -1);
  const [open, setOpen] = useState(false),
    [from, setFrom] = useState(
      task.start_date ?? `${yesterday.slice(0, 7)}-01`,
    ),
    [to, setTo] = useState(
      task.due_date < yesterday ? task.due_date : yesterday,
    ),
    [search, setSearch] = useState("");
  const [unit, setUnit] = useState<TaskQuantityUnit>(
    task.quantity_unit ?? task.progress?.unit ?? "video",
  );
  const [query, setQuery] = useState({ from, to, search: "", page: 1 }),
    [version, setVersion] = useState(0);
  const [result, setResult] = useState<ProgressSources | null>(null),
    [loading, setLoading] = useState(false),
    [saving, setSaving] = useState(false);
  const [selected, setSelected] = useState<Record<number, Selection>>({}),
    [revision, setRevision] = useState<number | null>(null),
    [confirmed, setConfirmed] = useState(false),
    [note, setNote] = useState("");
  const [error, setError] = useState(""),
    [success, setSuccess] = useState("");
  useEffect(() => {
    if (!open || query.from > query.to) return;
    const controller = new AbortController();
    getProgressSources(task.id, query, controller.signal)
      .then((data) => {
        if (!controller.signal.aborted) {
          setResult(data);
          setRevision((previous) => previous ?? data.expected_revision);
          setError("");
        }
      })
      .catch((cause) => {
        if (!controller.signal.aborted) {
          setError(
            cause instanceof ApiServiceError && cause.status === 403
              ? t("reconciliation.forbidden")
              : t("reconciliation.loadError"),
          );
          setResult(null);
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [open, query, task.id, version, t]);
  const values = Object.values(selected);
  function toggle(source: ProgressSource, checked: boolean) {
    setConfirmed(false);
    setSuccess("");
    setSelected((current) => {
      const next = { ...current };
      if (checked)
        next[source.report_item_id] = {
          source,
          outputs:
            unit === "video"
              ? source.suggested_outputs.map((row) => ({
                  ...row,
                  completed: true,
                }))
              : [],
        };
      else delete next[source.report_item_id];
      return next;
    });
  }
  async function submit() {
    if (saving) return;
    setError("");
    setSuccess("");
    const invalid =
      values.length === 0 ||
      values.length > 20 ||
      !confirmed ||
      note.trim().length < 5 ||
      revision === null ||
      values.reduce((n, row) => n + row.outputs.length, 0) > 200 ||
      values.some((row) => {
        const keys = row.outputs.map((output) =>
          taskOutputIdentity(output, unit),
        );
        return (
          row.outputs.length === 0 ||
          row.outputs.length > 50 ||
          new Set(keys).size !== keys.length ||
          row.outputs.some(
            (output) =>
              !output.name.trim() ||
              output.name.length > 200 ||
              (needsOutputReference(unit) &&
                (!output.reference?.trim() || output.reference.length > 200)),
          )
        );
      });
    if (invalid) {
      setError(t("reconciliation.invalid"));
      return;
    }
    setSaving(true);
    try {
      const data = await importProgressSources(task.id, {
        expected_revision: revision!,
        unit,
        confirmed,
        confirmation_note: note.trim(),
        entries: values.map((row) => ({
          report_item_id: row.source.report_item_id,
          source_hash: row.source.source_hash,
          outputs: row.outputs.map((output) => ({
            name: output.name.trim(),
            ...(output.reference ? { reference: output.reference.trim() } : {}),
          })),
        })),
      });
      setSelected({});
      setRevision(data.data.progress.revision);
      setConfirmed(false);
      setNote("");
      setSuccess(
        t("reconciliation.success", {
          count: data.data.created,
          sources: data.data.imported_sources,
        }),
      );
      setLoading(true);
      setVersion((v) => v + 1);
      onImported();
    } catch (cause) {
      setError(
        cause instanceof ApiServiceError
          ? Object.values(cause.validationErrors ?? {}).flat()[0] ||
              cause.message ||
              t("reconciliation.saveError")
          : t("reconciliation.saveError"),
      );
    } finally {
      setSaving(false);
    }
  }
  return (
    <ComponentCard
      title={t("reconciliation.title")}
      desc={t("reconciliation.hint")}
    >
      <Button
        type="button"
        size="sm"
        variant="outline"
        disabled={saving}
        onClick={() => {
          setOpen((value) => !value);
          if (!open && query.from <= query.to) setLoading(true);
        }}
      >
        {t(open ? "collapse" : "reconciliation.open")}
      </Button>
      {open && (
        <div className="space-y-4">
          <p className="text-theme-sm text-warning-700 dark:text-warning-300">
            {t("reconciliation.rule")}
          </p>
          <div className="grid gap-4 sm:grid-cols-3">
            <div>
              <Label htmlFor="reconcile-from">{t("reconciliation.from")}</Label>
              <KpsDatePicker
                id="reconcile-from"
                ariaLabel={t("reconciliation.from")}
                placeholder={t("reconciliation.from")}
                value={from}
                onChange={setFrom}
                minDate={task.start_date ?? undefined}
                maxDate={to}
                disabled={saving}
              />
            </div>
            <div>
              <Label htmlFor="reconcile-to">{t("reconciliation.to")}</Label>
              <KpsDatePicker
                id="reconcile-to"
                ariaLabel={t("reconciliation.to")}
                placeholder={t("reconciliation.to")}
                value={to}
                onChange={setTo}
                minDate={from}
                maxDate={task.due_date < yesterday ? task.due_date : yesterday}
                disabled={saving}
              />
            </div>
            <div>
              <Label htmlFor="reconcile-unit">{t("unit")}</Label>
              <Select
                id="reconcile-unit"
                value={unit}
                disabled={
                  saving || Boolean(task.quantity_unit) || values.length > 0
                }
                options={(
                  [
                    "video",
                    "ảnh",
                    "bài viết",
                    "sản phẩm",
                    "khách hàng",
                    "lượt chăm sóc",
                  ] as TaskQuantityUnit[]
                ).map((value) => ({ value, label: t(`units.${value}`) }))}
                onChange={(value) => setUnit(value as TaskQuantityUnit)}
              />
            </div>
          </div>
          <div className="flex flex-wrap gap-3">
            <div className="min-w-0 flex-1">
              <Input
                aria-label={t("reconciliation.search")}
                placeholder={t("reconciliation.search")}
                value={search}
                disabled={saving}
                maxLength={100}
                onChange={(event) => setSearch(event.target.value)}
              />
            </div>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={saving || loading || from > to}
              onClick={() => {
                setLoading(true);
                setQuery({ from, to, search, page: 1 });
                setVersion((v) => v + 1);
              }}
            >
              {t("reconciliation.load")}
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={saving}
              onClick={() => {
                setSelected({});
                setRevision(null);
                setConfirmed(false);
                setLoading(true);
                setVersion((v) => v + 1);
              }}
            >
              {t("reconciliation.clear")}
            </Button>
          </div>
          {(error || from > to) && (
            <p
              role="alert"
              className="text-theme-sm text-error-600 dark:text-error-400"
            >
              {error || t("reconciliation.invalidDates")}
            </p>
          )}
          {success && (
            <p
              role="status"
              className="text-theme-sm text-success-700 dark:text-success-400"
            >
              {success}
            </p>
          )}
          {loading && (
            <p className="text-theme-sm text-gray-500 dark:text-gray-400">
              {t("loading")}
            </p>
          )}
          {!loading && result?.data.length === 0 && (
            <p className="text-theme-sm text-gray-500 dark:text-gray-400">
              {t("reconciliation.empty")}
            </p>
          )}
          {result?.data.map((source) => (
            <article
              key={source.report_item_id}
              className="space-y-2 rounded-xl border border-gray-200 p-4 dark:border-gray-700"
            >
              <div className="flex flex-wrap items-start justify-between gap-2">
                <label className="flex min-w-0 items-start gap-2 font-medium text-gray-900 dark:text-white">
                  <input
                    type="checkbox"
                    aria-label={t("reconciliation.select", {
                      id: source.report_item_id,
                    })}
                    className="mt-1 size-4 accent-kps-primary"
                    disabled={
                      saving ||
                      loading ||
                      source.already_recorded ||
                      source.linked_elsewhere ||
                      (values.length >= 20 && !selected[source.report_item_id])
                    }
                    checked={Boolean(selected[source.report_item_id])}
                    onChange={(event) => toggle(source, event.target.checked)}
                  />
                  <span className="break-words">{source.content}</span>
                </label>
                <Link
                  className="text-theme-xs text-kps-primary underline dark:text-sidebar-selected"
                  to={`/reports/${source.report_id}#work-item-${source.report_item_id}`}
                >
                  {t("historySource", {
                    id: source.report_id,
                    date: formatDateOnly(source.report_date),
                  })}
                </Link>
              </div>
              <p className="text-theme-sm break-words whitespace-pre-wrap text-gray-600 dark:text-gray-300">
                {source.result}
              </p>
              {source.evidence_links.length > 0 && (
                <details className="text-theme-xs text-gray-500 dark:text-gray-400">
                  <summary className="cursor-pointer">
                    {t("reconciliation.evidence")}
                  </summary>
                  {source.evidence_links.map((link) => (
                    <p key={link} className="break-all">
                      {link}
                    </p>
                  ))}
                </details>
              )}
              {(source.already_recorded || source.linked_elsewhere) && (
                <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                  {t(
                    source.already_recorded
                      ? "reconciliation.already"
                      : "reconciliation.elsewhere",
                  )}
                </p>
              )}
            </article>
          ))}
          {(result?.meta.last_page ?? 1) > 1 && (
            <div className="flex gap-3">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={saving || loading || query.page <= 1}
                onClick={() => {
                  setLoading(true);
                  setQuery((current) => ({
                    ...current,
                    page: current.page - 1,
                  }));
                }}
              >
                {t("previous")}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={
                  saving ||
                  loading ||
                  query.page >= (result?.meta.last_page ?? 1)
                }
                onClick={() => {
                  setLoading(true);
                  setQuery((current) => ({
                    ...current,
                    page: current.page + 1,
                  }));
                }}
              >
                {t("next")}
              </Button>
            </div>
          )}
          {values.length > 0 && (
            <div className="space-y-4 border-t border-gray-200 pt-4 dark:border-gray-700">
              <p className="font-medium text-gray-900 dark:text-white">
                {t("reconciliation.selected", { count: values.length })}
              </p>
              {values.map((row) => (
                <fieldset
                  key={row.source.report_item_id}
                  className="space-y-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-800"
                  aria-label={t("reconciliation.selectionLabel", {
                    id: row.source.report_item_id,
                  })}
                >
                  <legend className="text-theme-xs font-medium text-gray-700 dark:text-gray-300">
                    {row.source.content} ·{" "}
                    {formatDateOnly(row.source.report_date)}
                  </legend>
                  <TaskOutputEditor
                    unit={unit}
                    outputs={row.outputs}
                    completedLocked
                    disabled={saving}
                    onChange={(outputs) => {
                      setConfirmed(false);
                      setSelected((current) => ({
                        ...current,
                        [row.source.report_item_id]: { ...row, outputs },
                      }));
                    }}
                  />
                </fieldset>
              ))}
              <div>
                <Label htmlFor="reconcile-note">
                  {t("reconciliation.note")}
                </Label>
                <TextArea
                  id="reconcile-note"
                  value={note}
                  rows={2}
                  disabled={saving}
                  onChange={setNote}
                />
              </div>
              <label className="flex items-start gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                <input
                  type="checkbox"
                  className="mt-1 size-4 accent-kps-primary"
                  checked={confirmed}
                  disabled={saving}
                  onChange={(event) => setConfirmed(event.target.checked)}
                />
                {t("reconciliation.confirm")}
              </label>
              <Button
                type="button"
                size="sm"
                disabled={saving || loading}
                onClick={() => void submit()}
                className="!bg-kps-primary hover:!bg-kps-primary-hover"
              >
                {t(saving ? "reconciliation.saving" : "reconciliation.apply")}
              </Button>
            </div>
          )}
        </div>
      )}
    </ComponentCard>
  );
}
