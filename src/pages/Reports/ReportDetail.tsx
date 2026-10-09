import ComponentCard from "@/components/common/ComponentCard";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import TextArea from "@/components/form/input/TextArea";
import Label from "@/components/form/Label";
import ReportStatusBadge from "@/components/reports/ReportStatusBadge";
import ReportWorkItemDetails from "@/components/reports/ReportWorkItemDetails";
import Button from "@/components/ui/button/Button";
import { useNotifications } from "@/hooks/useNotifications";
import { ChatIcon, TimeIcon } from "@/icons";
import { ApiServiceError } from "@/services/apiClient";
import { addReportComment, getReport } from "@/services/reportService";
import type { DailyReport } from "@/types/reports";
import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useParams } from "react-router";

const isAbortError = (error: unknown) =>
  error instanceof DOMException && error.name === "AbortError";

const formatDate = (date: string) => {
  const [year, month, day] = date.split("-");
  return `${day}/${month}/${year}`;
};

const formatDateTime = (value: string | null) =>
  value
    ? new Intl.DateTimeFormat("vi-VN", {
        dateStyle: "short",
        timeStyle: "short",
      }).format(new Date(value))
    : "—";

export default function ReportDetail() {
  const { t } = useTranslation("common", { keyPrefix: "reportDetail" });
  const { id } = useParams();
  const { markReportCommentsRead } = useNotifications();
  const [report, setReport] = useState<DailyReport | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [comment, setComment] = useState("");
  const [isSubmittingComment, setIsSubmittingComment] = useState(false);
  const [commentError, setCommentError] = useState<string | null>(null);
  const [commentSuccess, setCommentSuccess] = useState<string | null>(null);
  const markedReportRef = useRef<number | null>(null);
  const hasValidId = Boolean(id && /^\d+$/.test(id));

  useEffect(() => {
    const abortController = new AbortController();
    if (!id || !hasValidId) return () => abortController.abort();

    getReport(id, abortController.signal)
      .then((nextReport) => {
        setReport(nextReport);
        setLoadError(null);
      })
      .catch((error: unknown) => {
        if (isAbortError(error)) return;
        if (error instanceof ApiServiceError && error.status === 404) {
          setLoadError(t("errors.notFound"));
        } else if (error instanceof ApiServiceError && error.status === 403) {
          setLoadError(t("errors.forbidden"));
        } else if (error instanceof ApiServiceError && error.status === 0) {
          setLoadError(t("errors.network"));
        } else {
          setLoadError(t("errors.load"));
        }
      })
      .finally(() => {
        if (!abortController.signal.aborted) setIsLoading(false);
      });

    return () => abortController.abort();
  }, [hasValidId, id, t]);

  useEffect(() => {
    if (
      !report ||
      report.unread_comment_count < 1 ||
      markedReportRef.current === report.id
    ) {
      return;
    }

    markedReportRef.current = report.id;
    void markReportCommentsRead(report.id)
      .then(() => {
        setReport((current) =>
          current?.id === report.id
            ? { ...current, unread_comment_count: 0 }
            : current,
        );
      })
      .catch(() => undefined);
  }, [markReportCommentsRead, report]);

  const handleAddComment = async () => {
    const content = comment.trim();
    if (!report || !content) {
      setCommentError(t("comments.validation"));
      return;
    }

    setIsSubmittingComment(true);
    setCommentError(null);
    setCommentSuccess(null);
    try {
      const created = await addReportComment(report.id, content);
      setReport((current) =>
        current
          ? {
              ...current,
              comments: [...current.comments, created],
              comment_count: current.comment_count + 1,
            }
          : current,
      );
      setComment("");
      setCommentSuccess(t("comments.success"));
    } catch (error) {
      if (error instanceof ApiServiceError) {
        setCommentError(
          error.status === 0
            ? t("errors.network")
            : error.message || t("comments.error"),
        );
      } else {
        setCommentError(t("comments.error"));
      }
    } finally {
      setIsSubmittingComment(false);
    }
  };

  return (
    <>
      <PageMeta
        title={`${t("title")} | KIM PHỤC SẮC`}
        description={t("metaDescription")}
      />
      <PageBreadCrumb pageTitle={t("title")} />

      {!hasValidId ? (
        <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
          <p className="text-theme-sm text-error-600 dark:text-error-400">
            {t("errors.notFound")}
          </p>
          <Link
            to="/reports"
            className="mt-5 inline-flex rounded-lg border border-gray-300 px-4 py-3 text-theme-sm font-semibold text-gray-700 hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
          >
            {t("back")}
          </Link>
        </section>
      ) : isLoading ? (
        <div
          role="status"
          className="rounded-2xl border border-gray-200 bg-white px-6 py-14 text-center text-theme-sm text-gray-500 dark:border-gray-800 dark:bg-white/3 dark:text-gray-400"
        >
          {t("loading")}
        </div>
      ) : loadError || !report ? (
        <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
          <p className="text-theme-sm text-error-600 dark:text-error-400">
            {loadError ?? t("errors.load")}
          </p>
          <Link
            to="/reports"
            className="mt-5 inline-flex rounded-lg border border-gray-300 px-4 py-3 text-theme-sm font-semibold text-gray-700 hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
          >
            {t("back")}
          </Link>
        </section>
      ) : (
        <div className="space-y-6">
          <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs sm:p-6 dark:border-gray-800 dark:bg-white/3">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <p className="text-theme-sm text-gray-500 dark:text-gray-400">
                  {t("summary.date")}
                </p>
                <h2 className="mt-1 text-title-sm font-semibold text-gray-900 dark:text-white">
                  {formatDate(report.report_date)}
                </h2>
              </div>
              <div className="flex flex-wrap items-center gap-3">
                <span className="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-theme-xs font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">
                  <TimeIcon className="size-4" />
                  {report.is_locked ? t("summary.locked") : t("summary.open")}
                </span>
                <ReportStatusBadge status={report.overall_status} />
              </div>
            </div>

            <dl className="mt-6 grid grid-cols-1 gap-5 border-t border-gray-100 pt-5 sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800">
              {[
                [t("summary.employee"), report.employee.name],
                [t("summary.department"), report.department?.name ?? "—"],
                [t("summary.location"), report.location?.name ?? "—"],
                [t("summary.updatedAt"), formatDateTime(report.updated_at)],
              ].map(([label, value]) => (
                <div key={label}>
                  <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                    {label}
                  </dt>
                  <dd className="mt-1 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                    {value}
                  </dd>
                </div>
              ))}
            </dl>

            {report.actions.can_edit && (
              <div className="mt-5 flex justify-end">
                <Link
                  to="/reports/new"
                  className="inline-flex rounded-lg bg-kps-primary px-4 py-3 text-theme-sm font-semibold text-white transition hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none"
                >
                  {t("edit")}
                </Link>
              </div>
            )}
          </section>

          <ComponentCard title={t("items.title")} compact>
            <ReportWorkItemDetails items={report.items} />
          </ComponentCard>

          <ComponentCard title={t("comments.title")} compact>
            {report.comments.length === 0 ? (
              <p className="py-4 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                {t("comments.empty")}
              </p>
            ) : (
              <div className="space-y-3">
                {report.comments.map((item) => (
                  <article
                    key={item.id}
                    className="rounded-xl border border-gray-200 bg-gray-50/60 p-4 dark:border-gray-800 dark:bg-gray-900/50"
                  >
                    <div className="flex items-start gap-3">
                      <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-kps-primary/10 text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected">
                        <ChatIcon className="size-4.5" />
                      </span>
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                          <h3 className="text-theme-sm font-semibold text-gray-900 dark:text-white">
                            {item.author.name}
                          </h3>
                          <time className="text-theme-xs text-gray-500 dark:text-gray-400">
                            {formatDateTime(item.created_at)}
                          </time>
                        </div>
                        <p className="mt-2 whitespace-pre-wrap text-theme-sm text-gray-700 dark:text-gray-300">
                          {item.content}
                        </p>
                      </div>
                    </div>
                  </article>
                ))}
              </div>
            )}

            {report.actions.can_comment && (
              <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                <Label htmlFor="report-comment">
                  {t("comments.formLabel")}
                </Label>
                <TextArea
                  id="report-comment"
                  name="comment"
                  rows={3}
                  value={comment}
                  placeholder={t("comments.placeholder")}
                  disabled={isSubmittingComment}
                  error={Boolean(commentError)}
                  hint={commentError ?? undefined}
                  onChange={(value) => {
                    setComment(value);
                    setCommentError(null);
                    setCommentSuccess(null);
                  }}
                />
                {commentSuccess && (
                  <p className="mt-2 text-theme-sm text-success-600 dark:text-success-400">
                    {commentSuccess}
                  </p>
                )}
                <div className="mt-4 flex justify-end">
                  <Button
                    type="button"
                    size="sm"
                    className="!bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none"
                    disabled={isSubmittingComment || !comment.trim()}
                    onClick={() => void handleAddComment()}
                  >
                    {isSubmittingComment
                      ? t("comments.submitting")
                      : t("comments.submit")}
                  </Button>
                </div>
              </div>
            )}
          </ComponentCard>

          <div>
            <Link
              to="/reports"
              className="inline-flex rounded-lg border border-gray-300 px-4 py-3 text-theme-sm font-semibold text-gray-700 hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
            >
              {t("back")}
            </Link>
          </div>
        </div>
      )}
    </>
  );
}
