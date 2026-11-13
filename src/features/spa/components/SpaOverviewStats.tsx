import { useTranslation } from "react-i18next";
import { useAuthorization } from "@/hooks/useAuthorization";
import { canOpenWorkspacePath } from "@/utils/workspace";
import { CartIcon, CalenderIcon, TimeIcon, ChatIcon } from "@/icons";
import { getSpaOverview } from "../selectors";
import { spaMoney, spaReport, spaInvoiceBalance } from "../businessSelectors";
import type { SpaPreviewSnapshot } from "../types";
export default function SpaOverviewStats({
  snapshot,
  date,
}: {
  snapshot: SpaPreviewSnapshot;
  date: string;
}) {
  const { t } = useTranslation();
  const { workspaces } = useAuthorization();
  const allowed = (path: string) => canOpenWorkspacePath(workspaces, path);
  const stats = getSpaOverview({ ...snapshot, date }),
    report = spaReport(snapshot, date, date);
  const outstanding = snapshot.invoices
    .filter((i) => i.date <= date)
    .reduce((sum, i) => sum + spaInvoiceBalance(snapshot, i.id, date), 0);
  const unpaid = snapshot.invoices.filter(
    (i) => i.date <= date && spaInvoiceBalance(snapshot, i.id, date) > 0,
  ).length;
  const cards = [
    {
      label: t("spa.business.moneyToday"),
      value: spaMoney(report.collected),
      hint: t("spa.concept.paymentsCount", { count: report.payments.length }),
      icon: CartIcon,
      finance: true,
    },
    {
      label: t("spa.concept.appointmentsDate"),
      value: stats.appointments,
      hint: t("spa.concept.visitsCompleted", { count: report.completed }),
      icon: CalenderIcon,
    },
    {
      label: t("spa.concept.inProgress"),
      value: stats.inProgress,
      hint: t("spa.concept.waitingCount", { count: stats.waiting }),
      icon: TimeIcon,
    },
    {
      label: t("spa.business.outstanding"),
      value: spaMoney(outstanding),
      hint: t("spa.concept.unpaidCount", { count: unpaid }),
      icon: ChatIcon,
      finance: true,
    },
  ].filter((card) => !card.finance || allowed("/spa/cashier"));
  return (
    <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {cards.map(({ label, value, hint, icon: Icon }) => (
        <div
          key={label}
          className="rounded-xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-gray-900"
        >
          <dt className="flex items-center justify-between gap-3 text-theme-xs text-gray-500 dark:text-gray-400">
            {label}
            <span className="rounded-lg bg-kps-primary/5 p-2 text-kps-primary dark:bg-kps-primary/20 dark:text-gray-300">
              <Icon className="size-4" aria-hidden="true" />
            </span>
          </dt>
          <dd className="mt-3 text-title-sm font-semibold tracking-tight text-gray-900 dark:text-white">
            {value}
          </dd>
          <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
            {hint}
          </p>
        </div>
      ))}
    </dl>
  );
}
