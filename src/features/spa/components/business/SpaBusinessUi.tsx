import type { ReactNode } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableCell,
} from "@/components/ui/table";
import { spaFieldClasses } from "../../config";
export const spaPanel =
  "rounded-xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-gray-900";
export function SpaAction({
  children,
  onClick,
  secondary = false,
  disabled = false,
}: {
  children: ReactNode;
  onClick: () => void;
  secondary?: boolean;
  disabled?: boolean;
}) {
  return (
    <Button
      type="button"
      size="sm"
      variant={secondary ? "outline" : "primary"}
      className={
        secondary
          ? "min-h-11"
          : "min-h-11 !bg-kps-primary hover:!bg-kps-primary-hover"
      }
      onClick={onClick}
      disabled={disabled}
    >
      {children}
    </Button>
  );
}
export function SpaStats({
  items,
}: {
  items: { label: string; value: ReactNode }[];
}) {
  return (
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      {items.map((i) => (
        <div key={i.label} className={spaPanel}>
          <p className="text-sm text-gray-500 dark:text-gray-400">{i.label}</p>
          <p className="mt-2 text-xl font-semibold break-words text-gray-900 dark:text-white">
            {i.value}
          </p>
        </div>
      ))}
    </div>
  );
}
export function SpaHint({ children }: { children: ReactNode }) {
  return (
    <p className="text-sm leading-6 text-gray-500 dark:text-gray-400">
      {children}
    </p>
  );
}
export function SpaEmpty() {
  const { t } = useTranslation();
  return (
    <p className="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
      {t("spa.business.empty")}
    </p>
  );
}
export function SpaSearch({
  value,
  onChange,
}: {
  value: string;
  onChange: (value: string) => void;
}) {
  const { t } = useTranslation();
  return (
    <div className="w-full sm:max-w-sm">
      <Input
        type="search"
        value={value}
        aria-label={t("spa.business.search")}
        placeholder={t("spa.business.search")}
        onChange={(e) => onChange(e.target.value)}
        className={spaFieldClasses}
      />
    </div>
  );
}
export function SpaTable({
  headers,
  rows,
}: {
  headers: string[];
  rows: { id: string; cells: ReactNode[] }[];
}) {
  return (
    <div className="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-800">
      <Table className="text-sm">
        <TableHeader className="bg-gray-50 dark:bg-gray-800">
          <TableRow>
            {headers.map((h) => (
              <TableCell
                key={h}
                isHeader
                className="px-4 py-3 text-start font-medium text-gray-500 dark:text-gray-400"
              >
                {h}
              </TableCell>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((r) => (
            <TableRow
              key={r.id}
              className="border-t border-gray-100 dark:border-gray-800"
            >
              {r.cells.map((c, index) => (
                <TableCell
                  key={index}
                  className="px-4 py-3 text-gray-700 dark:text-gray-300"
                >
                  {c}
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
export function SpaTabs({
  items,
  value,
  onChange,
}: {
  items: { key: string; label: string }[];
  value: string;
  onChange: (key: string) => void;
}) {
  return (
    <div
      role="group"
      className="inline-flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-800"
    >
      {items.map((i) => (
        <button
          key={i.key}
          type="button"
          aria-pressed={i.key === value}
          onClick={() => onChange(i.key)}
          className={`min-h-11 rounded-lg border px-4 py-2 text-sm font-medium focus-visible:ring-2 focus-visible:ring-kps-primary focus-visible:outline-none ${i.key === value ? "border-gray-200 bg-white text-kps-primary-hover shadow-theme-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" : "border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"}`}
        >
          {i.label}
        </button>
      ))}
    </div>
  );
}
