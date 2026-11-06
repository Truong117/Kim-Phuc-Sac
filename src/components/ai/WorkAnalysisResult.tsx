import { useTranslation } from "react-i18next";
import {
  analysisSections,
  type AnalysisClaim,
  type AnalysisResult,
  type AnalysisSource,
  type WorkTotal,
} from "@/types/workReportAnalysis";
import WorkAnalysisSources from "./WorkAnalysisSources";

function uniqueClaims(claims: AnalysisClaim[]) {
  return [...new Map(claims.map((claim) => [claim.text, claim])).values()];
}

function ClaimList({ claims }: { claims: AnalysisClaim[] }) {
  return (
    <ul className="space-y-3">
      {claims.map((claim, index) => (
        <li
          key={index}
          className="flex gap-3 text-sm leading-7 text-gray-700 dark:text-gray-300"
        >
          <span
            aria-hidden="true"
            className="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-400 dark:bg-brand-300"
          />
          <p className="min-w-0 break-words whitespace-pre-wrap">
            {claim.text}
          </p>
        </li>
      ))}
    </ul>
  );
}

export default function WorkAnalysisResult({
  result,
  sources,
  employee = false,
  totals = [],
}: {
  result: AnalysisResult;
  sources: AnalysisSource[];
  employee?: boolean;
  totals?: WorkTotal[];
}) {
  const { t } = useTranslation("common", { keyPrefix: "workReportAnalysis" });
  const findings = uniqueClaims([
    ...result.overview,
    ...result.highlights,
    ...result.ongoing,
  ]);
  const attention = uniqueClaims([...result.attention, ...result.support]);
  const review = employee
    ? uniqueClaims([...findings, ...attention])
    : findings;
  const unclear = totals.filter(
    (total) =>
      total.status !== "confirmed" ||
      total.total === null ||
      !Number.isFinite(total.total) ||
      total.total < 0,
  );
  const recommendations = uniqueClaims(result.recommendations).sort(
    (a, b) => Number(b.priority === "high") - Number(a.priority === "high"),
  );
  return (
    <div className="space-y-6">
      {!employee && (
        <section className="space-y-3">
          <h3 className="font-semibold text-gray-900 dark:text-white">
            {t("reader.conclusions")}
          </h3>
          {review.length > 0 ? (
            <ClaimList claims={review} />
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {t("noFinding")}
            </p>
          )}
        </section>
      )}
      {!employee && attention.length > 0 && (
        <section className="space-y-3 border-s-2 border-warning-300 ps-4 dark:border-warning-700">
          <h3 className="font-semibold text-gray-900 dark:text-white">
            {t("reader.attention")}
          </h3>
          <ClaimList claims={attention} />
        </section>
      )}
      <section>
        {result.uncertainties.length > 0 || unclear.length > 0 ? (
          <details className="rounded-xl bg-warning-50 p-4 text-sm text-warning-800 dark:bg-warning-950/40 dark:text-warning-200">
            <summary className="cursor-pointer font-medium">
              {t("reader.clarify")} ·{" "}
              {uniqueClaims(result.uncertainties).length + unclear.length}
            </summary>
            <div className="mt-3 space-y-3">
              {unclear.length > 0 && (
                <>
                  <p className="text-xs leading-6">
                    {t("reader.quantityClarification")}
                  </p>
                  <ul className="list-disc space-y-1 ps-5">
                    {unclear.map((total, index) => (
                      <li key={index}>
                        {total.metric}
                        {total.unit ? ` (${total.unit})` : ""}
                      </li>
                    ))}
                  </ul>
                </>
              )}
              <ClaimList claims={uniqueClaims(result.uncertainties)} />
            </div>
          </details>
        ) : (
          <p className="text-xs text-gray-500 dark:text-gray-400">
            <span className="me-2 font-medium text-gray-700 dark:text-gray-300">
              {t("reader.clarify")}
            </span>
            {t("reader.noClarification")}
          </p>
        )}
      </section>
      {employee && (
        <section className="space-y-3">
          <h3 className="font-semibold text-gray-900 dark:text-white">
            {t("reader.review")}
          </h3>
          {review.length > 0 ? (
            <ClaimList claims={review} />
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {t("reader.noReview")}
            </p>
          )}
        </section>
      )}
      <section className="space-y-3">
        <h3 className="font-semibold text-gray-900 dark:text-white">
          {t("reader.proposals")}
        </h3>
        {recommendations.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {t("reader.noProposal")}
          </p>
        ) : (
          <>
            <p className="text-xs leading-6 text-gray-500 dark:text-gray-400">
              {t("reader.proposalHint")}
            </p>
            <div className="space-y-3">
              {recommendations.map((claim, index) => (
                <details
                  key={index}
                  className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
                >
                  <summary className="cursor-pointer text-sm leading-7 font-medium text-gray-900 dark:text-white">
                    {claim.priority && (
                      <span
                        className={`me-2 inline-block rounded-full px-2 py-0.5 text-xs ${claim.priority === "high" ? "bg-warning-50 text-warning-800 dark:bg-warning-950 dark:text-warning-200" : "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300"}`}
                      >
                        {t(`reader.priority.${claim.priority}`)}
                      </span>
                    )}
                    {claim.text}
                  </summary>
                  <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    {(
                      [
                        "rationale",
                        "impact",
                        "action",
                        "expected_outcome",
                        "verification",
                        "decision_needed",
                      ] as const
                    )
                      .filter((field) => claim[field]?.trim())
                      .map((field) => (
                        <div key={field}>
                          <dt className="font-medium text-gray-900 dark:text-white">
                            {t(`reader.decision.${field}`)}
                          </dt>
                          <dd className="mt-1 leading-7 break-words whitespace-pre-wrap text-gray-600 dark:text-gray-300">
                            {claim[field]}
                          </dd>
                        </div>
                      ))}
                  </dl>
                  {!claim.action && (
                    <p className="mt-3 text-xs leading-6 text-gray-500 dark:text-gray-400">
                      {t("reader.legacyProposal")}
                    </p>
                  )}
                </details>
              ))}
            </div>
          </>
        )}
      </section>
      {!employee && (
        <WorkAnalysisSources
          evidence={analysisSections.flatMap((section) =>
            result[section].flatMap((claim) => claim.evidence),
          )}
          sources={sources}
        />
      )}
    </div>
  );
}
