export const parseEvidenceLinks = (value: string): string[] =>
  value
    .split(/\r?\n/)
    .map((link) => link.trim())
    .filter(Boolean);
export const isEvidenceUrl = (value: string): boolean => {
  try {
    const url = new URL(value);
    return (
      ["http:", "https:"].includes(url.protocol) &&
      !url.username &&
      !url.password &&
      value.length <= 2048
    );
  } catch {
    return false;
  }
};
export const hasValidEvidenceLinks = (value: string): boolean => {
  const links = parseEvidenceLinks(value);
  return (
    links.length <= 10 &&
    new Set(links).size === links.length &&
    links.every(isEvidenceUrl)
  );
};
