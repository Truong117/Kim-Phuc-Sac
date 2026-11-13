/** Spreadsheet-safe CSV for synthetic preview records only. */
export const spaCsv = (rows: (string | number)[][]) =>
  "\uFEFF" +
  rows
    .map((row) =>
      row
        .map((cell) => {
          let value = String(cell);
          if (/^[\s]*[=+\-@\t\r]/.test(value)) value = `'${value}`;
          return `"${value.replace(/"/g, '""')}"`;
        })
        .join(","),
    )
    .join("\r\n");
export function downloadSpaCsv(name: string, rows: (string | number)[][]) {
  const url = URL.createObjectURL(
    new Blob([spaCsv(rows)], { type: "text/csv;charset=utf-8" }),
  );
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = `kps-spa-mau-${name}.csv`;
  anchor.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export const spaPreviewId = (prefix: string) =>
  `${prefix}-${crypto.randomUUID()}`;
