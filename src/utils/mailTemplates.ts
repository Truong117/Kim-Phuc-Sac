import type { TFunction } from "i18next";
import type { DailyDocumentsPage } from "@/types/dailyDocument";
import type { WorkReportAnalysis } from "@/types/workReportAnalysis";
import {
  reportText,
  reportClaimText,
  reportSections,
} from "./reportPresentation";
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

const section = (title: string, lines: string[]) =>
  `${title}\n${lines.map((line) => `- ${line}`).join("\n")}`;

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
          (group) => `${reportText(group.title)}: ${reportText(group.text)}`,
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
        result.comments.map((entry) => reportText(entry.text)),
      ),
    );
  if (result.recommendations.length)
    chunks.push(
      section(
        t("mailTemplates.recommendations"),
        result.recommendations.map((entry) => reportText(entry.text)),
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
      name: row.employee_name,
      date,
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
      start,
      end,
      date: end,
    }),
  ];
  if (run.is_mock) chunks.push(t("mailTemplates.mockWarning"));
  for (const { key, claims } of reportSections(run.result!)) {
    const lines = claims.map((claim) => {
      const text =
        key === "decisions"
          ? reportText(claim.text)
          : reportClaimText(claim, run.source_reports);
      return claim.priority === "high"
        ? `${t("workReportAnalysis.reader.priority.high")}: ${text}`
        : text;
    });
    if (key === "obstacles")
      lines.push(
        ...run.result!.uncertainties.map(
          (claim) =>
            `${t("workReportAnalysis.reader.clarify")}: ${reportClaimText(claim, run.source_reports)}`,
        ),
      );
    const hint =
      key === "decisions" && lines.length
        ? `${t("workReportAnalysis.reader.proposalHint")}\n`
        : "";
    chunks.push(
      `${t(`workReportAnalysis.form.${key}`)}\n${hint}${lines.length ? lines.map((line) => `- ${line}`).join("\n") : t(`workReportAnalysis.form.empty.${key}`)}`,
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
