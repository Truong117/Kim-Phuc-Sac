import { useCallback, useId, useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import { spaMoney } from "../../businessSelectors";
import { spaFieldClasses } from "../../config";
import useSpaBookingRead from "../../hooks/useSpaBookingRead";
import {
  listSpaBookingServices,
  type SpaBookingServiceOption,
} from "../../services/spaBookingService";
import { SpaAction, SpaHint } from "../business/SpaBusinessUi";
import SpaLivePagination from "./SpaLivePagination";

export default function SpaLiveBookingServiceField({
  selected,
  onSelect,
  invalid,
}: {
  selected: SpaBookingServiceOption | null;
  onSelect: (value: SpaBookingServiceOption | null) => void;
  invalid: boolean;
}) {
  const { t } = useTranslation();
  const id = useId();
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const request = useCallback(
    (signal: AbortSignal) =>
      listSpaBookingServices({ search: search.trim(), page }, signal),
    [search, page],
  );
  const { data, failed, loading, reload } = useSpaBookingRead(request, 200);
  const options = data?.data ?? [];
  const visible =
    selected && !options.some((item) => item.id === selected.id)
      ? [selected, ...options]
      : options;
  return (
    <div className="grid gap-2">
      <div>
        <Label htmlFor={`${id}-search`}>{t("spa.live.findService")}</Label>
        <Input
          id={`${id}-search`}
          type="search"
          value={search}
          maxLength={100}
          onChange={(event) => {
            setSearch(event.target.value);
            setPage(1);
          }}
          className={spaFieldClasses}
        />
      </div>
      <div>
        <Label htmlFor={`${id}-service`}>{t("spa.live.service")}</Label>
        <Select
          id={`${id}-service`}
          placeholder={t("spa.flow.chooseService")}
          value={selected?.id ?? ""}
          onChange={(value) =>
            onSelect(visible.find((item) => item.id === value) ?? null)
          }
          options={visible.map((item) => ({
            value: item.id,
            label: `${item.name} · ${t("spa.minutes", { count: item.duration_minutes })} · ${spaMoney(item.price)}`,
          }))}
          error={invalid}
          disabled={loading || failed}
          required
          className={spaFieldClasses}
        />
      </div>
      {loading && <p role="status">{t("spa.live.loading")}</p>}
      {failed && (
        <div className="space-y-2">
          <p role="alert">{t("spa.live.loadError")}</p>
          <SpaAction secondary onClick={reload}>
            {t("spa.live.retry")}
          </SpaAction>
        </div>
      )}
      {data && !data.data.length && (
        <SpaHint>{t("spa.live.noServices")}</SpaHint>
      )}
      {data && <SpaLivePagination meta={data.meta} onChange={setPage} />}
    </div>
  );
}
