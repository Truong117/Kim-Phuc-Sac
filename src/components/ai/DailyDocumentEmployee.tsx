import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import { formatDateOnly } from "@/utils/dateOnly";
import { isEvidenceUrl } from "@/utils/reportEvidence";
import type { DailyDocumentsPage } from "@/types/dailyDocument";

type Row = DailyDocumentsPage["reports"][number];
export default function DailyDocumentEmployee({
  row,
  busy,
  onRequest,
  onRetry,
}: {
  row: Row;
  busy: boolean;
  onRequest: () => void;
  onRetry: () => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "dailyDocuments" });
  const document = row.document;
  // Display the frozen inventory for historical analysis, including partial/failed analysis.
  const source = document?.source ?? row.source;
  return (
    <ComponentCard title={row.employee_name}>
      <div className="space-y-4 text-sm text-gray-700 dark:text-gray-300">
        <div className="flex flex-wrap items-center gap-3">
          <Link
            to={`/reports/${source.report_id}`}
            className="text-brand-600 underline dark:text-brand-300"
          >
            {t("source", {
              id: source.report_id,
              date: formatDateOnly(source.date),
            })}
          </Link>
          {source.is_makeup && <span>{t("makeup")}</span>}
          <span>{t("selfReported")}</span>
        </div>
        {row.source_changed && <p role="status">{t("sourceChanged")}</p>}
        {document?.requires_new_document && <p role="status">{t("contractChanged")}</p>}
        {document && (
          <div aria-live="polite">
            <p>
              {t(`status.${document.status}`)} ·{" "}
              {t("progress", {
                count: document.completed_units,
                total: document.total_units,
              })}
            </p>
            <p>
              {t(document.mode === "fake" ? "fake" : "model", {
                model: document.model,
              })}
            </p>
          </div>
        )}
        {(["COMPLETED", "IN_PROGRESS", "BLOCKED"] as const).map((status) => (
          <section key={status}>
            <h3 className="mb-2 font-semibold">{t(`groups.${status}`)}</h3>
            {source.items.filter((item) => item.status === status).length ===
            0 ? (
              <p>{t("none")}</p>
            ) : (
              <ul className="space-y-3">
                {source.items
                  .filter((item) => item.status === status)
                  .map((item) => (
                    <li
                      key={item.item_id}
                      className="rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                    >
                      <p className="font-medium break-words whitespace-pre-wrap">
                        {item.content}
                      </p>
                      {item.result && (
                        <p className="break-words whitespace-pre-wrap">
                          {t("result")}: {item.result}
                        </p>
                      )}
                      {item.issue && (
                        <p className="break-words whitespace-pre-wrap">
                          {t("issue")}: {item.issue}
                        </p>
                      )}
                      {item.note && (
                        <p className="break-words whitespace-pre-wrap">
                          {t("note")}: {item.note}
                        </p>
                      )}
                      {item.evidence_links.length > 0 && (
                        <div className="mt-2 space-y-1">
                          <p>{t("unverified")}</p>
                          {item.evidence_links
                            .filter(isEvidenceUrl)
                            .map((link) => (
                              <a
                                key={link}
                                href={link}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="block break-all text-brand-600 underline dark:text-brand-300"
                              >
                                {link}
                              </a>
                            ))}
                        </div>
                      )}
                    </li>
                  ))}
              </ul>
            )}
          </section>
        ))}
        <section>
          <h3 className="mb-2 font-semibold">{t("plan")}</h3>
          <p>{t("planHint")}</p>
          {source.next_day_plan.length ? (
            <ul className="list-disc ps-5">
              {source.next_day_plan.map((line, index) => (
                <li key={index} className="break-words whitespace-pre-wrap">
                  {line}
                </li>
              ))}
            </ul>
          ) : (
            <p>{t("notProvided")}</p>
          )}
        </section>
        {Boolean(document?.result?.work_groups?.length) && (
          <section className="space-y-3">
            <h3 className="font-semibold">{t("groupedWork")}</h3>
            {document?.result?.work_groups?.map((group, index) => (
              <article key={index} className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <h4 className="font-medium">{group.title}</h4>
                <p className="mt-2 leading-7 whitespace-pre-wrap">{group.text}</p>
                <details className="mt-2 text-xs">
                  <summary className="cursor-pointer">{t("groupSources")}</summary>
                  {group.evidence.map((ref) => <p key={`${ref.report_id}:${ref.item_id}`}>
                    <Link to={`/reports/${ref.report_id}#work-item-${ref.item_id}`} className="text-brand-600 underline dark:text-brand-300">{t("quote", { id: ref.item_id, quote: ref.quote })}</Link>
                  </p>)}
                </details>
              </article>
            ))}
          </section>
        )}
        <section>
          <h3 className="font-semibold">{t("totals")}</h3>
          {!document?.result?.work_totals.length ? (
            <p>{t("noTotals")}</p>
          ) : (
            <ul>
              {document.result.work_totals.map((total, index) => (
                <li key={index}>
                  {total.metric}:{" "}
                  {total.total === null
                    ? t("needsConfirmation")
                    : `${total.total} ${total.unit}`}
                </li>
              ))}
            </ul>
          )}
        </section>
        {(["comments", "recommendations"] as const).map((field) => (
          <section key={field}>
            <h3 className="font-semibold">{t(field)}</h3>
            {!document?.result?.[field].length ? (
              <p>{t("noReview")}</p>
            ) : (
              <ul>
                {document.result[field].map((entry, index) => (
                  <li
                    key={index}
                    className="mt-2 break-words whitespace-pre-wrap"
                  >
                    <p>{entry.text}</p>
                    <blockquote>
                      {t("quote", { quote: entry.quote, id: entry.item_id })}
                    </blockquote>
                  </li>
                ))}
              </ul>
            )}
          </section>
        ))}
        {document?.units
          .filter((unit) => unit.error_code)
          .map((unit) => (
            <p
              key={unit.id}
              role="status"
              className="text-error-600 dark:text-error-400"
            >
              {t("unitError", { part: unit.part + 1, code: unit.error_code })}
            </p>
          ))}
        <div className="flex flex-wrap gap-3">
          <Button
            onClick={onRequest}
            disabled={
              busy ||
              (!row.source_changed &&
                !document?.requires_new_document &&
                !!document &&
                ["pending", "running", "completed"].includes(document.status))
            }
          >
            {t("request")}
          </Button>
          {document?.units.some((unit) => unit.can_retry) && (
            <Button variant="outline" onClick={onRetry} disabled={busy}>
              {t("retry")}
            </Button>
          )}
        </div>
      </div>
    </ComponentCard>
  );
}
