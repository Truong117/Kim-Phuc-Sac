import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router";
import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import ComponentCard from "@/components/common/ComponentCard";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import CrmCustomerDetails, {
  type CrmAction,
} from "@/components/crm/CrmCustomerDetails";
import CrmForm from "@/components/crm/CrmForm";
import { useModal } from "@/hooks/useModal";
import { ApiServiceError } from "@/services/apiClient";
import {
  assignCrmCustomer,
  createCrmCustomer,
  getCrmCapabilities,
  getCrmCustomer,
  getCrmCustomers,
  recordCrmActivity,
  updateCrmOpportunity,
  type CrmCustomerDetail,
  type CrmFormValues,
  type CrmOpportunity,
} from "@/services/crmService";

export default function CrmCustomers() {
  const { t } = useTranslation("common", { keyPrefix: "crm" });
  const navigate = useNavigate();
  const customerId = Number(useParams().id) || null;
  const dialog = useModal();
  const [mode, setMode] = useState<{
    action: CrmAction;
    opportunity?: CrmOpportunity;
  }>({ action: "new" });
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [list, setList] = useState<Awaited<
    ReturnType<typeof getCrmCustomers>
  > | null>(null);
  const [detail, setDetail] = useState<CrmCustomerDetail | null>(null);
  const [capabilities, setCapabilities] = useState<Awaited<
    ReturnType<typeof getCrmCapabilities>
  > | null>(null);
  const [error, setError] = useState("");
  const [detailError, setDetailError] = useState("");
  const [message, setMessage] = useState("");
  const selectedCustomer = detail?.id === customerId ? detail : null;

  useEffect(() => {
    const controller = new AbortController();
    getCrmCapabilities(controller.signal)
      .then((value) => {
        if (controller.signal.aborted) return;
        setCapabilities(value);
        setError("");
      })
      .catch((failure: unknown) => {
        if (!controller.signal.aborted)
          setError(
            failure instanceof ApiServiceError && failure.status === 404
              ? t("disabled")
              : t("loadError"),
          );
      });
    return () => controller.abort();
  }, [revision, t]);

  const ready = capabilities?.ready;
  useEffect(() => {
    if (!ready) return;
    const controller = new AbortController();
    getCrmCustomers(query, page, controller.signal)
      .then((value) => {
        if (controller.signal.aborted) return;
        setList(value);
        setError("");
      })
      .catch(() => {
        if (!controller.signal.aborted) setError(t("loadError"));
      });
    return () => controller.abort();
  }, [ready, query, page, revision, t]);

  useEffect(() => {
    if (!ready || customerId === null) return;
    const controller = new AbortController();
    getCrmCustomer(customerId, controller.signal)
      .then((value) => {
        if (controller.signal.aborted) return;
        setDetail(value);
        setDetailError("");
      })
      .catch((failure: unknown) => {
        if (!controller.signal.aborted) {
          setDetail(null);
          setDetailError(
            failure instanceof ApiServiceError && failure.status === 404
              ? t("notFound")
              : t("loadError"),
          );
        }
      });
    return () => controller.abort();
  }, [ready, customerId, revision, t]);

  const open = (action: CrmAction, opportunity?: CrmOpportunity) => {
    setMode({ action, opportunity });
    setMessage("");
    dialog.openModal();
  };

  const save = async (values: CrmFormValues, requestKey: string) => {
    if (mode.action === "new") {
      const created = await createCrmCustomer(requestKey, values);
      void navigate("/customers/" + created.id);
      setPage(1);
      setQuery("");
      setSearch("");
    } else if (detail) {
      if (mode.action === "assignment")
        await assignCrmCustomer(detail, requestKey, values);
      else if (mode.action === "stage" && mode.opportunity)
        await updateCrmOpportunity(detail.id, mode.opportunity, values);
      else if (mode.action !== "stage")
        await recordCrmActivity(detail.id, mode.action, requestKey, values);
    }
    dialog.closeModal();
    setRevision((value) => value + 1);
    setMessage(
      t(
        ["assignment", "stage"].includes(mode.action)
          ? "saved"
          : "savedActivity",
      ),
    );
  };

  return (
    <>
      <PageMeta
        title={t("title") + " | KIM PHỤC SẮC"}
        description={t("description")}
      />
      <PageBreadCrumb pageTitle={t("title")} />
      <div className="space-y-4 kps-ai-theme">
        {message && (
          <p
            role="status"
            className="rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-950 dark:text-success-300"
          >
            {message}
          </p>
        )}
        {error && (
          <div
            role="alert"
            className="flex flex-wrap items-center gap-3 text-sm text-error-600 dark:text-error-300"
          >
            {error}{" "}
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => setRevision((value) => value + 1)}
            >
              {t("retry")}
            </Button>
          </div>
        )}
        {!capabilities && !error && (
          <p className="text-sm text-gray-600 dark:text-gray-400">
            {t("loading")}
          </p>
        )}
        {capabilities && !ready && (
          <ComponentCard
            title={t("title")}
            desc={t(capabilities.enabled ? "pendingMigration" : "disabled")}
          >
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => setRevision((value) => value + 1)}
            >
              {t("retry")}
            </Button>
          </ComponentCard>
        )}
        {ready && (
          <div className="grid items-start gap-4 xl:grid-cols-2">
            <ComponentCard
              title={t("list")}
              desc={list ? t("listCount", { count: list.total }) : undefined}
              fillHeight
              className={
                selectedCustomer
                  ? "min-w-0 xl:self-stretch xl:contain-size"
                  : "min-w-0"
              }
            >
              <div className="flex shrink-0 flex-wrap justify-between gap-2">
                <form
                  className="grid max-w-92 min-w-0 flex-auto grid-cols-[minmax(0,1fr)_auto] gap-2"
                  onSubmit={(event) => {
                    event.preventDefault();
                    setQuery(search.trim());
                    setPage(1);
                  }}
                >
                  <Input
                    aria-label={t("search")}
                    placeholder={t("search")}
                    maxLength={200}
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                  />
                  <Button size="sm" type="submit" variant="outline">
                    {t("searchAction")}
                  </Button>
                </form>
                {capabilities?.can_create && (
                  <Button type="button" size="sm" onClick={() => open("new")}>
                    {t("actions.new")}
                  </Button>
                )}
              </div>
              <ul
                key={`${query}:${list?.current_page}`}
                aria-label={t("list")}
                className="min-h-0 flex-1 divide-y divide-gray-200 overflow-y-auto dark:divide-gray-700"
              >
                {list?.data.map((customer) => (
                  <li key={customer.id}>
                    <Link
                      to={"/customers/" + customer.id}
                      aria-current={
                        customerId === customer.id ? "page" : undefined
                      }
                      className="flex flex-wrap items-center justify-between gap-2 rounded-lg px-2 py-2.5 text-sm hover:bg-gray-50 aria-[current=page]:bg-kps-primary/10 dark:hover:bg-gray-800"
                    >
                      <span className="min-w-0">
                        <span className="block font-semibold break-words text-gray-900 dark:text-gray-100">
                          {customer.name}
                        </span>
                        <span className="text-xs text-gray-500 dark:text-gray-400">
                          {customer.code}
                          {customer.channel &&
                            " · " +
                              t("channels." + customer.channel, {
                                defaultValue: customer.channel,
                              })}
                        </span>
                      </span>
                      <span className="text-gray-700 dark:text-gray-300">
                        {customer.owner_name || t("unassigned")}
                      </span>
                    </Link>
                  </li>
                ))}
                {list?.data.length === 0 && (
                  <li className="text-sm text-gray-600 dark:text-gray-400">
                    {t("emptyCustomers")}
                  </li>
                )}
              </ul>
              {list && list.last_page > 1 && (
                <div className="flex shrink-0 items-center justify-between gap-2 text-sm text-gray-700 dark:text-gray-300">
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={page <= 1}
                    onClick={() => setPage(page - 1)}
                  >
                    {t("previous")}
                  </Button>
                  <span>
                    {list.current_page} / {list.last_page}
                  </span>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={page >= list.last_page}
                    onClick={() => setPage(page + 1)}
                  >
                    {t("next")}
                  </Button>
                </div>
              )}
            </ComponentCard>
            {selectedCustomer && (
              <CrmCustomerDetails customer={selectedCustomer} open={open} />
            )}
            {customerId !== null && !selectedCustomer && (
              <p className="text-sm text-gray-600 dark:text-gray-400">
                {detailError || t("loading")}
              </p>
            )}
          </div>
        )}
      </div>
      {dialog.isOpen && capabilities && (
        <CrmForm
          action={mode.action}
          opportunity={mode.opportunity}
          today={capabilities.business_date}
          onClose={dialog.closeModal}
          onSave={save}
        />
      )}
    </>
  );
}
