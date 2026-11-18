import { useTranslation } from "react-i18next";
import type { SpaBookingPage } from "../../services/spaBookingService";
import { SpaAction } from "../business/SpaBusinessUi";

export default function SpaLivePagination({
  meta,
  onChange,
}: {
  meta: SpaBookingPage<unknown>["meta"];
  onChange: (page: number) => void;
}) {
  const { t } = useTranslation();
  if (meta.last_page <= 1) return null;
  return (
    <nav
      aria-label={t("spa.live.pagination")}
      className="flex flex-wrap items-center gap-2"
    >
      <SpaAction
        secondary
        onClick={() => onChange(meta.current_page - 1)}
        disabled={meta.current_page <= 1}
      >
        {t("spa.live.previousPage")}
      </SpaAction>
      <span className="px-2 text-sm text-gray-500 dark:text-gray-400">
        {t("spa.live.pageInfo", {
          page: meta.current_page,
          totalPages: meta.last_page,
        })}
      </span>
      <SpaAction
        secondary
        onClick={() => onChange(meta.current_page + 1)}
        disabled={meta.current_page >= meta.last_page}
      >
        {t("spa.live.nextPage")}
      </SpaAction>
    </nav>
  );
}
