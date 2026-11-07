import type { TFunction } from "i18next";
import type { DailyDocumentsPage } from "@/types/dailyDocument";
import type {
  AnalysisClaim,
  WorkReportAnalysis,
} from "@/types/workReportAnalysis";
import { formatDateOnly } from "./dateOnly";

export const mailTemplateIds = [
  "daily",
  "weekly",
  "weekly_plan",
  "monthly_plan",
  "issue",
] as const;
export type MailTemplateId = (typeof mailTemplateIds)[number];
export interface MailTemplateContent {
  subject: string;
  body: string;
}
export type DailyMailSource = DailyDocumentsPage["reports"][number];
export const DAILY_SUMMARY_SOURCE = "daily-summary";
export const dailyMailReady = (row: DailyMailSource) =>
  row.document?.status === "completed" &&
  row.document.result !== null &&
  !row.document.requires_new_document &&
  row.source_changed === false;
export const weeklyMailReady = (run: WorkReportAnalysis) =>
  run.status === "completed" &&
  run.period.days === 7 &&
  run.source_changed === false &&
  !run.requires_new_run;
export const dailySummaryMailReady = (run: WorkReportAnalysis, date: string) =>
  run.status === "completed" &&
  run.period.days === 1 &&
  run.period.start_date === date &&
  run.period.end_date === date &&
  run.department_id === null &&
  run.source_changed === false &&
  !run.requires_new_run;

export function staticMailTemplate(
  id: Exclude<MailTemplateId, "daily" | "weekly">,
  fromName: string,
  t: TFunction,
): MailTemplateContent {
  return {
    subject: t(`mailTemplates.presets.${id}.subject`),
    body: t(`mailTemplates.presets.${id}.body`, { fromName }),
  };
}

const references = (
  items: { report_id: number; item_id: number; quote: string }[],
  t: TFunction,
) =>
  items
    .map((entry) =>
      t("mailTemplates.reference", {
        report: entry.report_id,
        item: entry.item_id,
        quote: entry.quote,
      }),
    )
    .join("\n");
const section = (title: string, lines: string[]) =>
  `${title}\n${lines.map((line) => `- ${line}`).join("\n")}`;

function weeklyClaimText(claim: AnalysisClaim, t: TFunction) {
  const details: string[] = [claim.text];
  if (claim.priority)
    details.push(t(`workReportAnalysis.reader.priority.${claim.priority}`));
  for (const key of [
    "rationale",
    "impact",
    "action",
    "expected_outcome",
    "verification",
    "decision_needed",
  ] as const) {
    if (claim[key])
      details.push(
        `${t(`workReportAnalysis.reader.decision.${key}`)}: ${claim[key]}`,
      );
  }
  details.push(references(claim.evidence, t));
  return details.filter(Boolean).join("\n");
}

export function dailyMailTemplate(
  row: DailyMailSource,
  fromName: string,
  origin: string,
  t: TFunction,
  page = 1,
): MailTemplateContent {
  if (!dailyMailReady(row)) throw new Error("MAIL_AI_SOURCE_UNAVAILABLE");
  const document = row.document!;
  const result = document.result!;
  const date = formatDateOnly(document.source.date);
  const chunks = [
    t("mailTemplates.greeting"),
    t("mailTemplates.dailyHeading", { date, name: row.employee_name }),
  ];
  if (document.mode === "fake") chunks.push(t("mailTemplates.mockWarning"));
  if (result.work_groups?.length)
    chunks.push(
      section(
        t("mailTemplates.work"),
        result.work_groups.map(
          (group) =>
            `${group.title}: ${group.text}\n${references(group.evidence, t)}`,
        ),
      ),
    );
  if (result.work_totals.length)
    chunks.push(
      section(
        t("mailTemplates.totals"),
        result.work_totals.map((total) =>
          total.status === "confirmed" &&
          total.total !== null &&
          Number.isFinite(total.total) &&
          total.total >= 0
            ? `${total.metric}: ${total.total} ${total.unit}`
            : `${total.metric} (${total.unit}): ${t("mailTemplates.needsConfirmation")}`,
        ),
      ),
    );
  if (result.comments.length)
    chunks.push(
      section(
        t("mailTemplates.comments"),
        result.comments.map(
          (entry) =>
            `${entry.text}\n${references([{ report_id: document.report_id, item_id: entry.item_id, quote: entry.quote }], t)}`,
        ),
      ),
    );
  if (result.recommendations.length)
    chunks.push(
      section(
        t("mailTemplates.recommendations"),
        result.recommendations.map(
          (entry) =>
            `${entry.text}\n${references([{ report_id: document.report_id, item_id: entry.item_id, quote: entry.quote }], t)}`,
        ),
      ),
    );
  const url = new URL("/ai/insights", origin);
  url.search = new URLSearchParams({
    view: "daily",
    date: document.source.date,
    page: String(page),
  }).toString();
  chunks.push(
    t("mailTemplates.dailySource", {
      id: document.id,
      report: document.report_id,
    }),
    `${t("mailTemplates.fullReport")} ${url.href}`,
    t("mailTemplates.reviewHint"),
    fromName,
  );
  return {
    subject: t("mailTemplates.dailySubject", { date, name: row.employee_name }),
    body: chunks.join("\n\n"),
  };
}

export function weeklyMailTemplate(
  run: WorkReportAnalysis,
  fromName: string,
  origin: string,
  t: TFunction,
): MailTemplateContent {
  if (!weeklyMailReady(run) || !run.result)
    throw new Error("MAIL_AI_SOURCE_UNAVAILABLE");
  return analysisMailTemplate(run, fromName, origin, t, "weekly");
}

export function dailySummaryMailTemplate(
  run: WorkReportAnalysis,
  date: string,
  fromName: string,
  origin: string,
  t: TFunction,
): MailTemplateContent {
  if (!dailySummaryMailReady(run, date) || !run.result)
    throw new Error("MAIL_AI_SOURCE_UNAVAILABLE");
  return analysisMailTemplate(run, fromName, origin, t, "dailySummary");
}

function analysisMailTemplate(
  run: WorkReportAnalysis,
  fromName: string,
  origin: string,
  t: TFunction,
  kind: "weekly" | "dailySummary",
): MailTemplateContent {
  const start = formatDateOnly(run.period.start_date),
    end = formatDateOnly(run.period.end_date);
  const chunks = [
    t("mailTemplates.greeting"),
    t(`mailTemplates.${kind}Heading`, { start, end, date: end }),
    t(`mailTemplates.${kind}Source`, {
      id: run.id,
      revision: run.revision.number,
    }),
  ];
  if (run.is_mock) chunks.push(t("mailTemplates.mockWarning"));
  for (const key of [
    "overview",
    "highlights",
    "ongoing",
    "attention",
    "support",
    "uncertainties",
    "recommendations",
  ] as const) {
    const claims = run.result![key];
    if (claims.length)
      chunks.push(
        section(
          t(`workReportAnalysis.sections.${key}`),
          claims.map((claim) => weeklyClaimText(claim, t)),
        ),
      );
  }
  const url = new URL("/ai/insights", origin);
  url.search = new URLSearchParams({
    analysis: String(run.id),
    end: run.period.end_date,
    days: String(run.period.days),
    ...(run.department_id ? { department: String(run.department_id) } : {}),
  }).toString();
  chunks.push(
    `${t("mailTemplates.fullReport")} ${url.href}`,
    t("mailTemplates.reviewHint"),
    fromName,
  );
  return {
    subject: t(`mailTemplates.${kind}Subject`, { start, end, date: end }),
    body: chunks.join("\n\n"),
  };
}
