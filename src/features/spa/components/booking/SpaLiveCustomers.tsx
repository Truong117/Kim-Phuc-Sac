import { useCallback, useState } from "react";
import { useTranslation } from "react-i18next";
import { useLocation, useSearchParams } from "react-router";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Button from "@/components/ui/button/Button";
import { useModal } from "@/hooks/useModal";
import { PlusIcon } from "@/icons";
import { spaFieldClasses } from "../../config";
import useSpaBookingRead from "../../hooks/useSpaBookingRead";
import {
  listSpaCustomers,
  type SpaBookingCapabilities,
  type SpaSavedCustomer,
} from "../../services/spaBookingService";
import SpaAvatar from "../SpaAvatar";
import { spaPanel } from "../business/SpaBusinessUi";
import SpaLiveCustomerDialog from "./SpaLiveCustomerDialog";

interface SpaLiveCustomersProps {
  capabilities: SpaBookingCapabilities;
}

export default function SpaLiveCustomers({
  capabilities,
}: SpaLiveCustomersProps) {
  const { t } = useTranslation();
  const [params] = useSearchParams();
  const location = useLocation();
  const headerSearch = (
    location.state as { spaCustomerSearch?: unknown } | null
  )?.spaCustomerSearch;
  const initialSearch = (
    typeof headerSearch === "string" ? headerSearch : (params.get("q") ?? "")
  )
    .trim()
    .slice(0, 100);
  if (!capabilities.viewCustomers)
    return <p role="alert">{t("spa.live.accessDenied")}</p>;
  return (
    <CustomerDirectory
      key={location.key}
      capabilities={capabilities}
      initialSearch={initialSearch}
    />
  );
}

function CustomerDirectory({
  capabilities,
  initialSearch,
}: SpaLiveCustomersProps & { initialSearch: string }) {
  const { t } = useTranslation();
  const [input, setInput] = useState(initialSearch);
  const [search, setSearch] = useState(initialSearch);
  const [page, setPage] = useState(1);
  const [saved, setSaved] = useState<SpaSavedCustomer>();
  const [editing, setEditing] = useState<SpaSavedCustomer>();
  const editor = useModal();
  const request = useCallback(
    (signal: AbortSignal) => listSpaCustomers({ search, page }, signal),
    [search, page],
  );
  const { data, loading, failed, reload } = useSpaBookingRead(request);
  const openEditor = (customer?: SpaSavedCustomer) => {
    setEditing(customer);
    editor.openModal();
  };

  return (
    <>
      <PageBreadCrumb
        spa
        pageTitle={t("spa.navigation.customers")}
        eyebrow={t("spa.concept.eyebrows.customers")}
        description={t("spa.live.customersDescription")}
        actions={
          capabilities.createCustomer && (
            <Button
              type="button"
              size="sm"
              startIcon={<PlusIcon className="size-4" aria-hidden="true" />}
              className="!bg-kps-primary hover:!bg-kps-primary-hover"
              onClick={() => openEditor()}
            >
              {t("spa.live.newCustomer")}
            </Button>
          )
        }
      />
      <section className="space-y-4" aria-label={t("spa.live.customerList")}>
        <form
          role="search"
          className={`${spaPanel} flex flex-wrap items-end gap-3`}
          onSubmit={(event) => {
            event.preventDefault();
            setSaved(undefined);
            setPage(1);
            setSearch(input.trim());
            reload();
          }}
        >
          <div className="w-full sm:max-w-sm">
            <Label htmlFor="spa-live-customer-search">
              {t("spa.flow.findCustomer")}
            </Label>
            <Input
              id="spa-live-customer-search"
              type="search"
              autoComplete="off"
              value={input}
              onChange={(event) => setInput(event.target.value)}
              maxLength={100}
              className={spaFieldClasses}
            />
          </div>
          <Button type="submit" size="sm" variant="outline">
            {t("spa.business.search")}
          </Button>
        </form>
        {saved && (
          <p
            role="status"
            className="text-sm text-success-700 dark:text-success-400"
          >
            {t("spa.live.customerSaved", { name: saved.name })}
          </p>
        )}
        {loading ? (
          <p role="status" className="text-sm text-gray-500 dark:text-gray-400">
            {t("spa.live.loading")}
          </p>
        ) : failed ? (
          <div
            className={`${spaPanel} flex flex-wrap items-center justify-between gap-3`}
          >
            <p
              role="alert"
              className="text-sm text-error-700 dark:text-error-400"
            >
              {t("spa.live.loadError")}
            </p>
            <Button type="button" size="sm" variant="outline" onClick={reload}>
              {t("spa.live.retry")}
            </Button>
          </div>
        ) : (
          data && (
            <>
              <p className="text-sm text-gray-500 dark:text-gray-400">
                {t("spa.flow.customerCount", { count: data.meta.total })}
              </p>
              {data.data.length ? (
                <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                  {data.data.map((customer) => (
                    <li key={customer.id} className={`${spaPanel} min-w-0`}>
                      <div className="flex items-start gap-3">
                        <SpaAvatar name={customer.name} />
                        <div className="min-w-0">
                          <h3 className="text-sm font-semibold break-words text-gray-900 dark:text-white">
                            {customer.name}
                          </h3>
                          <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            <bdi>{customer.phone}</bdi>
                          </p>
                        </div>
                      </div>
                      {customer.note && (
                        <p className="mt-3 text-sm break-words whitespace-pre-wrap text-gray-600 dark:text-gray-300">
                          {customer.note}
                        </p>
                      )}
                      {capabilities.editCustomer && (
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="mt-4"
                          onClick={() => openEditor(customer)}
                        >
                          {t("spa.flow.editCustomer")}
                        </Button>
                      )}
                    </li>
                  ))}
                </ul>
              ) : (
                <p
                  role="status"
                  className={`${spaPanel} text-sm text-gray-500 dark:text-gray-400`}
                >
                  {t("spa.flow.noCustomers")}
                </p>
              )}
              {data.meta.last_page > 1 && (
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t("spa.live.pageInfo", {
                      page: data.meta.current_page,
                      totalPages: data.meta.last_page,
                    })}
                  </p>
                  <div className="flex gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={page <= 1}
                      onClick={() => setPage((value) => value - 1)}
                    >
                      {t("spa.live.previousPage")}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={page >= data.meta.last_page}
                      onClick={() => setPage((value) => value + 1)}
                    >
                      {t("spa.live.nextPage")}
                    </Button>
                  </div>
                </div>
              )}
            </>
          )
        )}
      </section>
      {editor.isOpen &&
        (editing ? capabilities.editCustomer : capabilities.createCustomer) && (
          <SpaLiveCustomerDialog
            key={editing?.id ?? "new"}
            customer={editing}
            onClose={editor.closeModal}
            onSaved={(customer) => {
              editor.closeModal();
              setSaved(customer);
              reload();
            }}
          />
        )}
    </>
  );
}
