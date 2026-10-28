type GoogleDocsSourceReference = {
  key: string;
  origin: string;
  evidence: Record<string, unknown>;
};

type SourcePassageReference = {
  source_group: GoogleDocsSourceReference | null;
  source_context: GoogleDocsSourceReference | null;
};

const googleDocsTabKey = (passage: SourcePassageReference): string | null => {
  const source = [passage.source_group, passage.source_context].find(
    (candidate) => candidate?.origin === "GOOGLE_DOCS_TAB",
  );
  if (!source) return null;

  const tabId = source.evidence.tab_id;
  return typeof tabId === "string" && tabId.trim() !== ""
    ? `tab:${tabId}`
    : `group:${source.key}`;
};

export const spansMultipleGoogleDocsTabs = (
  passages: readonly SourcePassageReference[],
): boolean => {
  const tabKeys = new Set(
    passages
      .map(googleDocsTabKey)
      .filter((value): value is string => value !== null),
  );

  return tabKeys.size > 1;
};
