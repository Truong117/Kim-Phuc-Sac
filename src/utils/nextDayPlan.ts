export const parseNextDayPlan = (value: string): string[] =>
  value
    .split(/\r\n|\r|\n/)
    .map((line) => line.trim())
    .filter(Boolean);

export const normalizeNextDayPlan = (value: string): string =>
  parseNextDayPlan(value).join("\n");
