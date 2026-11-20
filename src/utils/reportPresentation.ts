import type {
  AnalysisClaim,
  AnalysisResult,
  AnalysisSource,
} from "@/types/workReportAnalysis";

// Presentation only: retain the saved text and evidence unchanged for audit/retry.
export function reportText(text: string): string {
  return text.replace(
    /^\s*(?:(?:Ghi nhận từ nguồn|Nhận xét có căn cứ|Đề xuất)\s*:\s*)+/u,
    "",
  );
}

export function uniqueReportClaims(
  claims: readonly AnalysisClaim[],
): AnalysisClaim[] {
  const unique = new Map<string, AnalysisClaim>();
  for (const claim of claims) {
    // Same title with different decision details remains a separate proposal.
    const key = JSON.stringify([
      reportText(claim.text),
      claim.priority,
      claim.rationale,
      claim.impact,
      claim.action,
      claim.expected_outcome,
      claim.verification,
      claim.decision_needed,
    ]);
    const previous = unique.get(key);
    unique.set(
      key,
      previous
        ? {
            ...previous,
            evidence: [
              ...new Map(
                [...previous.evidence, ...claim.evidence].map((ref) => [
                  JSON.stringify(ref),
                  ref,
                ]),
              ).values(),
            ],
          }
        : claim,
    );
  }
  return [...unique.values()];
}

export function reportClaimText(
  claim: AnalysisClaim,
  sources: AnalysisSource[],
): string {
  const text = reportText(claim.text);
  const names = [
    ...new Set(
      claim.evidence.flatMap((ref) => {
        const source = sources.find(
          (source) =>
            source.id === ref.report_id &&
            source.item_ids.includes(ref.item_id),
        );
        return source?.employee_name ? [source.employee_name] : [];
      }),
    ),
  ];
  return names.length && !names.every((name) => text.includes(name))
    ? `${names.join(", ")} — ${text}`
    : text;
}

export function reportSections(result: AnalysisResult, verbatim = false) {
  const sections = [
    { key: "results", claims: [...result.overview, ...result.highlights] },
    { key: "ongoing", claims: result.ongoing },
    { key: "obstacles", claims: [...result.attention, ...result.support] },
    { key: "decisions", claims: result.recommendations },
  ] as const;

  if (verbatim) return sections;
  return sections.map(({ key, claims }) => {
    const unique = uniqueReportClaims(claims);
    if (key === "decisions") {
      unique.sort(
        (a, b) => Number(b.priority === "high") - Number(a.priority === "high"),
      );
    }
    return { key, claims: unique };
  });
}
