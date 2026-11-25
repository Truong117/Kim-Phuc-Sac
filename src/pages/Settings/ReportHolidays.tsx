import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import ReportHolidayForm from "@/components/settings/ReportHolidayForm";
import ReportHolidaysTable from "@/components/settings/ReportHolidaysTable";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { useModal } from "@/hooks/useModal";
import { CheckCircleIcon, PlusIcon } from "@/icons";
import { ApiServiceError } from "@/services/apiClient";
import {
  createReportHoliday,
  deleteReportHoliday,
  getReportHolidays,
  updateReportHoliday,
} from "@/services/reportHolidayService";
import type {
  ReportHoliday,
  ReportHolidayInput,
  ReportHolidaysResponse,
} from "@/types/reportHolidays";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";

export default function ReportHolidays() {
  const { t } = useTranslation("common", { keyPrefix: "reportHolidays" });
  const [data, setData] = useState<ReportHolidaysResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [reload, setReload] = useState(0);
  const [editing, setEditing] = useState<ReportHoliday | null | undefined>();
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<ReportHoliday | null>(null);
  const deleteModal = useModal();
  const closeDeleteModal = () => {
    if (saving) return;
    deleteModal.closeModal();
    setDeleteTarget(null);
    setSaveError(null);
  };

  useEffect(() => {
    const controller = new AbortController();
    getReportHolidays(controller.signal)
      .then((result) => {
        if (!controller.signal.aborted) setData(result);
      })
      .catch(() => {
        if (!controller.signal.aborted) setLoadError(true);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [reload]);

  const refresh = () => {
    setLoading(true);
    setLoadError(false);
    setReload((value) => value + 1);
  };
  const failed = (error: unknown) => {
    const message =
      error instanceof ApiServiceError
        ? Object.values(error.validationErrors ?? {})
            .flat()
            .join(" ") || error.message
        : t("errors.save");
    if (
      error instanceof ApiServiceError &&
      [403, 404, 409].includes(error.status)
    ) {
      setEditing(undefined);
      setDeleteTarget(null);
      deleteModal.closeModal();
      setNotice(message);
      refresh();
    } else setSaveError(message);
  };
  const accept = (holiday: ReportHoliday) => {
    setData((current) =>
      current
        ? {
            ...current,
            data: [
              ...current.data.filter((row) => row.id !== holiday.id),
              holiday,
            ].sort(
              (a, b) => a.start_date.localeCompare(b.start_date) || a.id - b.id,
            ),
          }
        : current,
    );
  };
  const save = async (input: ReportHolidayInput) => {
    if (!data?.meta.can_manage || saving) return;
    setSaving(true);
    setSaveError(null);
    setNotice(null);
    try {
      const result = editing
        ? await updateReportHoliday(editing, input)
        : await createReportHoliday(input);
      accept(result);
      setEditing(undefined);
      setNotice(t("saved", { name: result.name }));
    } catch (error) {
      failed(error);
    } finally {
      setSaving(false);
    }
  };
  const toggle = async (holiday: ReportHoliday) => {
    if (!data?.meta.can_manage || saving) return;
    setSaving(true);
    setSaveError(null);
    setNotice(null);
    try {
      const result = await updateReportHoliday(holiday, {
        is_active: !holiday.is_active,
      });
      accept(result);
      setNotice(
        t(result.is_active ? "enabled" : "disabled", { name: result.name }),
      );
    } catch (error) {
      failed(error);
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async () => {
    if (!deleteTarget || !data?.meta.can_manage || saving) return;
    setSaving(true);
    setSaveError(null);
    setNotice(null);
    try {
      await deleteReportHoliday(deleteTarget);
      setData((current) =>
        current
          ? {
              ...current,
              data: current.data.filter((row) => row.id !== deleteTarget.id),
            }
          : current,
      );
      deleteModal.closeModal();
      setDeleteTarget(null);
      setNotice(t("deleted", { name: deleteTarget.name }));
    } catch (error) {
      failed(error);
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <PageMeta
        title={`${t("title")} | KIM PHỤC SẮC`}
        description={t("description")}
      />
      <PageBreadCrumb pageTitle={t("title")} />
      <div className="space-y-6">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <p className="text-theme-sm text-gray-500 dark:text-gray-400">
            {t("description")}
          </p>
          {data?.meta.can_manage && !loadError && (
            <Button
              size="sm"
              disabled={
                loading || saving || editing !== undefined || deleteModal.isOpen
              }
              startIcon={<PlusIcon className="size-4" />}
              className="bg-kps-primary hover:bg-kps-primary-hover"
              onClick={() => {
                setEditing(null);
                setSaveError(null);
                setNotice(null);
              }}
            >
              {t("addTitle")}
            </Button>
          )}
        </div>
        <div className="flex gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/3">
          <CheckCircleIcon className="mt-0.5 size-6 shrink-0 text-success-600 dark:text-success-400" />
          <div>
            <p className="text-theme-sm font-semibold text-gray-800 dark:text-white/90">
              {t("makeupTitle")}
            </p>
            <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
              {t("makeupDescription")}
            </p>
          </div>
        </div>
        {notice && (
          <p
            role="status"
            className="text-theme-sm text-gray-700 dark:text-gray-300"
          >
            {notice}
          </p>
        )}
        {saveError && editing === undefined && !deleteModal.isOpen && (
          <p
            role="alert"
            className="text-theme-sm text-error-600 dark:text-error-400"
          >
            {saveError}
          </p>
        )}
        {loading ? (
          <p
            role="status"
            className="py-8 text-center text-theme-sm text-gray-500 dark:text-gray-400"
          >
            {t("loading")}
          </p>
        ) : loadError || !data ? (
          <div
            role="alert"
            className="space-y-3 rounded-xl bg-error-50 p-5 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
          >
            <p>{t("errors.load")}</p>
            <Button size="sm" variant="outline" onClick={refresh}>
              {t("retry")}
            </Button>
          </div>
        ) : (
          <>
            {!data.meta.can_manage && (
              <p className="text-theme-sm text-gray-500 dark:text-gray-400">
                {t("readOnly")}
              </p>
            )}
            {editing !== undefined && data.meta.can_manage && (
              <ReportHolidayForm
                key={editing?.id ?? "new"}
                holiday={editing}
                rows={data.data}
                businessDate={data.meta.business_date}
                workingDays={data.meta.working_days}
                saving={saving}
                error={saveError}
                onSave={(input) => void save(input)}
                onCancel={() => {
                  setEditing(undefined);
                  setSaveError(null);
                }}
              />
            )}
            <ReportHolidaysTable
              rows={data.data}
              businessDate={data.meta.business_date}
              workingDays={data.meta.working_days}
              canManage={data.meta.can_manage}
              disabled={saving || editing !== undefined || deleteModal.isOpen}
              onEdit={(holiday) => {
                setEditing(holiday);
                setSaveError(null);
                setNotice(null);
              }}
              onToggle={(holiday) => void toggle(holiday)}
              onDelete={(holiday) => {
                setDeleteTarget(holiday);
                setSaveError(null);
                setNotice(null);
                deleteModal.openModal();
              }}
            />
          </>
        )}
      </div>
      <Modal
        isOpen={deleteModal.isOpen}
        onClose={closeDeleteModal}
        showCloseButton={false}
        className="max-w-lg p-6"
        ariaLabelledBy="holiday-delete-title"
      >
        <h2
          id="holiday-delete-title"
          className="text-lg font-semibold text-gray-900 dark:text-white"
        >
          {t("deleteTitle")}
        </h2>
        <p className="mt-3 text-theme-sm text-gray-600 dark:text-gray-300">
          {t("deleteDescription", { name: deleteTarget?.name ?? "" })}
        </p>
        {saveError && (
          <p
            role="alert"
            className="mt-4 text-theme-sm text-error-600 dark:text-error-400"
          >
            {saveError}
          </p>
        )}
        <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            disabled={saving}
            onClick={closeDeleteModal}
          >
            {t("cancel")}
          </Button>
          <Button
            type="button"
            variant="destructive"
            disabled={saving}
            onClick={() => void confirmDelete()}
          >
            {t(saving ? "deleting" : "deleteConfirm")}
          </Button>
        </div>
      </Modal>
    </>
  );
}
