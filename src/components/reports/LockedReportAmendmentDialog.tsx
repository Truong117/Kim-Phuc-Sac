import LockedReportNewItemFields from "./LockedReportNewItemFields";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { ApiServiceError } from "@/services/apiClient";
import { amendLockedReport, getReport } from "@/services/reportService";
import type { DailyReport, LockedReportAmendment } from "@/types/reports";
import {
  amendmentFields,
  collectLockedReportChanges,
  lockedAmendmentItemLimit,
  newAmendmentItemErrors,
  type AmendmentItemDraft,
  type NewAmendmentItemDraft,
} from "@/utils/lockedReportAmendment";
import { useWeeklyPlanV3NavigationSafety } from "@/utils/weeklyPlanV3NavigationSafety";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  report: DailyReport;
  amendment: LockedReportAmendment;
  onClose: () => void;
  onResolved: (report: DailyReport, saved: boolean) => void;
};

export default function LockedReportAmendmentDialog({
  report,
  amendment,
  onClose,
  onResolved,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "reportDetail" });
  const [items, setItems] = useState<Record<number, AmendmentItemDraft>>({});
  const [addedItems, setAddedItems] = useState<NewAmendmentItemDraft[]>([]);
  const [deletedItemIds, setDeletedItemIds] = useState<Set<number>>(
    () => new Set(),
  );
  const [showNewItemErrors, setShowNewItemErrors] = useState(false);
  const newKey = useRef(0);
  const [plan, setPlan] = useState<string>();
  const [reason, setReason] = useState("");
  const [pending, setPending] = useState<"save" | "reload" | null>(null);
  const [mustReload, setMustReload] = useState(false);
  const [error, setError] = useState("");
  const pendingRef = useRef(false);
  const reloadController = useRef<AbortController | null>(null);
  const changes = collectLockedReportChanges(report, amendment, {
    items,
    plan,
    addedItems,
    deletedItemIds,
  });
  const activeCount =
    report.items.length -
    (changes.changes.deleted_item_ids?.length ?? 0) +
    (changes.changes.added_items?.length ?? 0);
  const changesStructure = Boolean(
    changes.changes.deleted_item_ids?.length ||
    changes.changes.added_items?.length,
  );
  const dirty = changes.hasChanges || Boolean(reason.trim());
  const warning = t("lockedAmendment.discard");
  useWeeklyPlanV3NavigationSafety(dirty, warning);
  useEffect(() => () => reloadController.current?.abort(), []);

  const close = () => {
    if (pendingRef.current || (dirty && !window.confirm(warning))) return;
    onClose();
  };

  const addItem = () => {
    const item: NewAmendmentItemDraft = {
      key: ++newKey.current,
      status: "IN_PROGRESS",
      content: "",
      result: "",
      note: "",
      issue: "",
      evidence: "",
    };
    setAddedItems((current) => [...current, item]);
  };

  const toggleDeleted = (id: number) => {
    setDeletedItemIds((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (
      pendingRef.current ||
      mustReload ||
      !report.is_locked ||
      !amendment.allowed ||
      !amendment.expected_revision ||
      !changes.hasChanges
    ) {
      return;
    }
    const explanation = reason.trim();
    if (amendment.reason_required && !explanation) {
      setError(t("lockedAmendment.reasonMissing"));
      document.getElementById("locked-amendment-reason")?.focus();
      return;
    }
    if (changes.hasInvalidRequiredText) {
      setError(t("lockedAmendment.requiredText"));
      return;
    }

    if (amendment.can_add_items) {
      for (const item of addedItems) {
        const [field] = Object.keys(newAmendmentItemErrors(item));
        if (field) {
          setShowNewItemErrors(true);
          setError(t("lockedAmendment.invalidNewItem"));
          document
            .getElementById(`locked-amendment-new-${item.key}-${field}`)
            ?.focus();
          return;
        }
      }
    }
    if (
      changes.changes.added_items?.length &&
      activeCount > lockedAmendmentItemLimit
    ) {
      setError(
        t("lockedAmendment.itemLimit", { count: lockedAmendmentItemLimit }),
      );
      return;
    }
    if (
      changesStructure &&
      activeCount === 0 &&
      !(changes.changes.next_day_plan ?? report.next_day_plan)?.trim()
    ) {
      setError(t("lockedAmendment.emptyReport"));
      return;
    }

    pendingRef.current = true;
    setPending("save");
    setError("");
    try {
      const latest = await amendLockedReport(report.id, {
        expected_revision: amendment.expected_revision,
        ...(amendment.reason_required ? { reason: explanation } : {}),
        ...changes.changes,
      });
      if (latest.id !== report.id) throw new Error("Mismatched report ID");
      onResolved(latest, true);
    } catch (failure: unknown) {
      if (
        failure instanceof ApiServiceError &&
        ([403, 404, 409].includes(failure.status) ||
          failure.code === "LOCKED_AMENDMENT_NOT_READY")
      ) {
        setMustReload(true);
        setError(
          t(
            failure.code === "LOCKED_AMENDMENT_STALE"
              ? "lockedAmendment.conflict"
              : "lockedAmendment.unavailable",
          ),
        );
      } else {
        setError(
          failure instanceof ApiServiceError && failure.status === 422
            ? t("lockedAmendment.validation")
            : t("lockedAmendment.saveError"),
        );
      }
    } finally {
      pendingRef.current = false;
      setPending(null);
    }
  };

  const reload = async () => {
    if (
      pendingRef.current ||
      !window.confirm(t("lockedAmendment.reloadConfirm"))
    )
      return;
    const controller = new AbortController();
    reloadController.current = controller;
    pendingRef.current = true;
    setPending("reload");
    try {
      const latest = await getReport(report.id, controller.signal);
      if (controller.signal.aborted) return;
      if (latest.id !== report.id) throw new Error("Mismatched report ID");
      onResolved(latest, false);
    } catch {
      if (!controller.signal.aborted)
        setError(t("lockedAmendment.reloadError"));
    } finally {
      if (!controller.signal.aborted) {
        pendingRef.current = false;
        setPending(null);
      }
    }
  };

  const editable = new Set(amendment.editable_item_ids);
  const deletable = new Set(amendment.deletable_item_ids);
  const canChangeRows = amendment.can_add_items || deletable.size > 0;
  const hasProtectedItems =
    editable.size < report.items.length ||
    (amendment.deletable_item_ids !== undefined &&
      deletable.size < report.items.length);
  const editingDisabled = Boolean(pending) || mustReload;
  return (
    <Modal
      isOpen
      showCloseButton={false}
      onClose={close}
      ariaLabelledBy="locked-amendment-title"
      className="my-auto max-h-[calc(100dvh-2rem)] max-w-3xl overflow-y-auto p-5 sm:p-6"
    >
      <h2
        id="locked-amendment-title"
        className="text-lg font-semibold text-gray-900 dark:text-white"
      >
        {t("lockedAmendment.action")}
      </h2>
      <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
        {t("lockedAmendment.hint", { employee: report.employee.name })}
      </p>
      <form
        className="mt-5 space-y-5"
        onSubmit={(event) => void submit(event)}
        noValidate
      >
        {hasProtectedItems && (
          <p className="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">
            {t("lockedAmendment.protectedItems")}
          </p>
        )}
        {canChangeRows && (
          <p className="text-sm text-gray-600 dark:text-gray-400">
            {t("lockedAmendment.rowChangesHint")}
          </p>
        )}
        {report.items.map((item, index) => {
          const canEdit = editable.has(item.id);
          const canDelete = deletable.has(item.id);
          const pendingDeletion = canDelete && deletedItemIds.has(item.id);
          if (!canEdit && !canDelete) return null;
          return (
            <fieldset
              key={item.id}
              className="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700"
              disabled={editingDisabled}
            >
              <legend className="px-2 font-medium text-gray-900 dark:text-white">
                {t("items.itemTitle", { number: index + 1 })}
              </legend>
              {pendingDeletion ? (
                <p
                  role="status"
                  className="text-sm text-error-700 dark:text-error-300"
                >
                  {t("lockedAmendment.pendingDeletion", {
                    content: item.content,
                  })}
                </p>
              ) : !canEdit ? (
                <p className="text-sm text-gray-600 dark:text-gray-400">
                  {item.content}
                </p>
              ) : (
                amendmentFields.map((field) => {
                  const fieldId = `locked-amendment-item-${item.id}-${field}`;
                  const label = t(`items.${field}`);
                  return (
                    <div key={field}>
                      <Label htmlFor={fieldId}>{label}</Label>
                      <TextArea
                        id={fieldId}
                        rows={2}
                        maxLength={5000}
                        value={items[item.id]?.[field] ?? item[field] ?? ""}
                        placeholder={label}
                        onChange={(value) =>
                          setItems((current) => ({
                            ...current,
                            [item.id]: { ...current[item.id], [field]: value },
                          }))
                        }
                      />
                    </div>
                  );
                })
              )}
              {canDelete && (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => toggleDeleted(item.id)}
                >
                  {t(
                    pendingDeletion
                      ? "lockedAmendment.undoDeletion"
                      : "lockedAmendment.deleteItem",
                  )}
                </Button>
              )}
            </fieldset>
          );
        })}
        {amendment.can_add_items && (
          <>
            {addedItems.map((item, index) => (
              <LockedReportNewItemFields
                key={item.key}
                draft={item}
                number={index + 1}
                showErrors={showNewItemErrors}
                disabled={editingDisabled}
                onChange={(patch) =>
                  setAddedItems((current) =>
                    current.map((row) =>
                      row.key === item.key ? { ...row, ...patch } : row,
                    ),
                  )
                }
                onRemove={() =>
                  setAddedItems((current) =>
                    current.filter((row) => row.key !== item.key),
                  )
                }
              />
            ))}
            <div>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={addItem}
                disabled={
                  editingDisabled || activeCount >= lockedAmendmentItemLimit
                }
              >
                {t("lockedAmendment.addItem")}
              </Button>
              {activeCount >= lockedAmendmentItemLimit && (
                <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                  {t("lockedAmendment.itemLimit", {
                    count: lockedAmendmentItemLimit,
                  })}
                </p>
              )}
            </div>
          </>
        )}
        {amendment.can_edit_next_day_plan ? (
          <div>
            <Label htmlFor="locked-amendment-plan">
              {t("lockedAmendment.plan")}
            </Label>
            <TextArea
              id="locked-amendment-plan"
              maxLength={5000}
              value={plan ?? report.next_day_plan ?? ""}
              placeholder={t("lockedAmendment.plan")}
              onChange={setPlan}
              disabled={editingDisabled}
            />
          </div>
        ) : (
          <p className="text-sm text-gray-600 dark:text-gray-400">
            {t("lockedAmendment.protectedPlan")}
          </p>
        )}
        {amendment.reason_required && (
          <div>
            <Label htmlFor="locked-amendment-reason">
              {t("lockedAmendment.reason")}
            </Label>
            <TextArea
              id="locked-amendment-reason"
              required
              rows={2}
              maxLength={1000}
              value={reason}
              placeholder=""
              hint={t("lockedAmendment.reasonHint")}
              onChange={setReason}
              disabled={editingDisabled}
            />
          </div>
        )}
        {error && (
          <p
            role="alert"
            className="text-sm text-error-700 dark:text-error-300"
          >
            {error}
          </p>
        )}
        <div className="flex flex-wrap justify-end gap-2">
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={close}
            disabled={Boolean(pending)}
          >
            {t("lockedAmendment.cancel")}
          </Button>
          {mustReload ? (
            <Button
              type="button"
              size="sm"
              onClick={() => void reload()}
              disabled={Boolean(pending)}
            >
              {t(
                pending
                  ? "lockedAmendment.reloading"
                  : "lockedAmendment.reload",
              )}
            </Button>
          ) : (
            <Button
              type="submit"
              size="sm"
              className="!bg-kps-primary hover:!bg-kps-primary-hover"
              disabled={Boolean(pending) || !changes.hasChanges}
            >
              {t(pending ? "lockedAmendment.saving" : "lockedAmendment.save")}
            </Button>
          )}
        </div>
      </form>
    </Modal>
  );
}
