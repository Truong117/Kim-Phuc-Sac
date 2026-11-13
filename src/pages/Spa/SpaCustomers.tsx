import SpaCustomerBusinessSummary from "@/features/spa/components/SpaCustomerBusinessSummary";
import { spaFieldClasses } from "@/features/spa/config";
import { useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate, useSearchParams } from "react-router";
import PageMeta from "@/components/common/PageMeta";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
import SpaAvatar from "@/features/spa/components/SpaAvatar";
import Select from "@/components/form/Select";
import { useAuthorization } from "@/hooks/useAuthorization";
import { canOpenWorkspacePath } from "@/utils/workspace";
import { spaMoney, spaInvoiceBalance } from "@/features/spa/businessSelectors";
import { downloadSpaCsv } from "@/features/spa/csv";
import { PlusIcon, ArrowRightIcon } from "@/icons";
import ComponentCard from "@/components/common/ComponentCard";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Button from "@/components/ui/button/Button";
import { useModal } from "@/hooks/useModal";
import { useSpaPreview } from "@/features/spa/hooks/useSpaPreviewSnapshot";
import { normalizeSpaText } from "@/features/spa/previewWorkflow";
import SpaCustomerDialog from "@/features/spa/components/SpaCustomerDialog";
import SpaBookingDialog from "@/features/spa/components/SpaBookingDialog";
import SpaStatusBadge from "@/features/spa/components/SpaStatusBadge";

export default function SpaCustomers() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { snapshot } = useSpaPreview();
  const [params, setParams] = useSearchParams();
  const query = params.get("q") ?? "";
  const setQuery = (value: string) =>
    setParams(
      (current) => {
        const next = new URLSearchParams(current);
        if (value) next.set("q", value);
        else next.delete("q");
        return next;
      },
      { replace: true },
    );
  const { workspaces } = useAuthorization();
  const canSeeMoney = canOpenWorkspacePath(workspaces, "/spa/cashier");
  const [filter, setFilter] = useState("all");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [bookingId, setBookingId] = useState<string | null>(null);
  const [notice, setNotice] = useState(false);
  const editor = useModal();
  const profileRef = useRef<HTMLElement>(null);
  const revealProfile = () =>
    requestAnimationFrame(() => {
      profileRef.current?.scrollIntoView({
        block: "start",
        behavior: "smooth",
      });
    });
  const selected = snapshot.customers.find((item) => item.id === selectedId);
  const customers = snapshot.customers.filter((item) =>
    normalizeSpaText(`${item.name} ${item.phone}`).includes(
      normalizeSpaText(query),
    ),
  );
  const filteredCustomers = customers.filter(
    (customer) =>
      filter === "all" ||
      (filter === "packages"
        ? snapshot.packages.some(
            (p) =>
              p.customerId === customer.id &&
              p.used < p.total &&
              p.expires >= snapshot.date,
          )
        : snapshot.invoices.some(
            (i) =>
              i.customerId === customer.id &&
              spaInvoiceBalance(snapshot, i.id) > 0,
          )),
  );
  const history = snapshot.appointments
    .filter((item) => item.customerId === selected?.id)
    .toSorted((a, b) =>
      `${b.date}${b.startTime}`.localeCompare(`${a.date}${a.startTime}`),
    );
  return (
    <>
      <PageMeta
        title={t("spa.meta.section", { name: t("spa.navigation.customers") })}
        description={t("spa.meta.description")}
      />
      <SpaPageHeading
        section="customers"
        actions={
          <div className="flex flex-wrap gap-2">
            <Button
              size="sm"
              variant="outline"
              onClick={() =>
                downloadSpaCsv("khach-hang", [
                  [t("spa.business.customer"), t("spa.concept.phone")],
                  ...filteredCustomers.map((customer) => [
                    customer.name,
                    customer.phone,
                  ]),
                ])
              }
            >
              {t("spa.concept.exportCustomers")}
            </Button>
            <Button
              size="sm"
              className="!bg-kps-primary hover:!bg-kps-primary-hover"
              startIcon={<PlusIcon className="size-4" />}
              onClick={() => {
                setEditingId(null);
                editor.openModal();
              }}
            >
              {t("spa.flow.newCustomer")}
            </Button>
          </div>
        }
      />
      <div className="space-y-5">
        <div className="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-theme-xs dark:border-gray-800 dark:bg-gray-900">
          <div className="w-full sm:max-w-sm">
            <Label htmlFor="spa-customer-search">
              {t("spa.flow.findCustomer")}
            </Label>
            <Input
              id="spa-customer-search"
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              className={spaFieldClasses}
            />
          </div>
          <div className="w-full sm:w-56">
            <Label htmlFor="spa-customer-filter">
              {t("spa.concept.customerFilter")}
            </Label>
            <Select
              id="spa-customer-filter"
              placeholder={t("spa.concept.customerFilter")}
              value={filter}
              onChange={setFilter}
              options={[
                "all",
                "packages",
                ...(canSeeMoney ? ["debt"] : []),
              ].map((value) => ({
                value,
                label: t(`spa.concept.customerFilters.${value}`),
              }))}
            />
          </div>
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">
            {t("spa.flow.customerCount", { count: filteredCustomers.length })}
          </p>
        </div>
        {notice && (
          <p
            role="status"
            className="text-sm text-success-700 dark:text-success-400"
          >
            {t("spa.flow.saved")}
          </p>
        )}
        <section
          aria-label={t("spa.flow.customerList")}
          className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
          {filteredCustomers.map((customer) => {
            const visits = snapshot.appointments.filter(
              (a) => a.customerId === customer.id && a.status === "arrived",
            ).length;
            const invoiceIds = new Set(
              snapshot.invoices
                .filter((i) => i.customerId === customer.id)
                .map((i) => i.id),
            );
            const collected = snapshot.payments
              .filter((payment) => invoiceIds.has(payment.invoiceId))
              .reduce((sum, payment) => sum + payment.amount, 0);
            const packages = snapshot.packages.filter(
              (p) =>
                p.customerId === customer.id &&
                p.used < p.total &&
                p.expires >= snapshot.date,
            ).length;
            return (
              <article
                key={customer.id}
                aria-label={customer.name}
                className="rounded-xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-gray-900"
              >
                <div className="flex items-center gap-3">
                  <SpaAvatar name={customer.name} />
                  <div>
                    <h3 className="text-sm font-semibold text-gray-900 dark:text-white">
                      {customer.name}
                    </h3>
                    <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                      {customer.id}
                    </p>
                  </div>
                </div>
                <dl className="my-5 grid grid-cols-2 gap-3 border-b border-gray-100 pb-5 dark:border-gray-800">
                  <div>
                    <dd className="text-xl font-semibold text-gray-900 dark:text-white">
                      {visits}
                    </dd>
                    <dt className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                      {t("spa.concept.customerVisits")}
                    </dt>
                  </div>
                  {canSeeMoney && (
                    <div>
                      <dd className="text-xl font-semibold text-gray-900 dark:text-white">
                        {spaMoney(collected)}
                      </dd>
                      <dt className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                        {t("spa.concept.customerCollected")}
                      </dt>
                    </div>
                  )}
                </dl>
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                  {customer.phone}
                </p>
                <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                  {t("spa.concept.activePackages", { count: packages })}
                </p>
                <div className="mt-4 flex flex-wrap gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    endIcon={
                      <ArrowRightIcon className="size-4 rtl:rotate-180" />
                    }
                    onClick={() => {
                      setSelectedId(customer.id);
                      revealProfile();
                    }}
                  >
                    {t("spa.flow.profile")}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                      setEditingId(customer.id);
                      editor.openModal();
                    }}
                  >
                    {t("spa.flow.editCustomer")}
                  </Button>
                  <Button
                    size="sm"
                    className="!bg-kps-primary hover:!bg-kps-primary-hover"
                    onClick={() => setBookingId(customer.id)}
                  >
                    {t("spa.flow.bookForCustomer")}
                  </Button>
                </div>
              </article>
            );
          })}
          {!filteredCustomers.length && (
            <p
              role="status"
              className="text-sm text-gray-500 dark:text-gray-400"
            >
              {t("spa.flow.noCustomers")}
            </p>
          )}
        </section>
        {selected && (
          <aside ref={profileRef} className="scroll-mt-24">
            <ComponentCard title={t("spa.flow.profile")}>
              {selected ? (
                <div className="space-y-4">
                  <p className="font-semibold text-gray-900 dark:text-white">
                    {selected.name}
                  </p>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    {selected.phone}
                  </p>
                  {selected.note && (
                    <p className="text-sm break-words whitespace-pre-wrap text-gray-600 dark:text-gray-300">
                      {selected.note}
                    </p>
                  )}
                  <SpaCustomerBusinessSummary customerId={selected.id} />
                  <h4 className="font-medium text-gray-900 dark:text-white">
                    {t("spa.flow.customerHistory")}
                  </h4>
                  {history.length ? (
                    <ul className="space-y-3">
                      {history.map((item) => (
                        <li
                          key={item.id}
                          className="rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200"
                        >
                          <p className="mb-2">
                            {item.date} · {item.startTime} · {item.id}
                          </p>
                          <SpaStatusBadge
                            kind="appointment"
                            status={item.status}
                          />
                          <p className="mt-2">
                            {item.tasks
                              .map(
                                (task) =>
                                  task.serviceName ??
                                  t(`spa.services.${task.serviceKey}`),
                              )
                              .join(" · ")}
                          </p>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                      {t("spa.flow.noHistory")}
                    </p>
                  )}
                </div>
              ) : (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  {t("spa.flow.chooseProfile")}
                </p>
              )}
            </ComponentCard>
          </aside>
        )}
      </div>
      {editor.isOpen && (
        <SpaCustomerDialog
          key={editingId ?? "new"}
          customer={snapshot.customers.find((item) => item.id === editingId)}
          onClose={editor.closeModal}
          onSaved={(id) => {
            editor.closeModal();
            setSelectedId(id);
            setQuery("");
            setNotice(true);
            revealProfile();
          }}
        />
      )}
      {bookingId && (
        <SpaBookingDialog
          customerId={bookingId}
          date={snapshot.date}
          onClose={() => setBookingId(null)}
          onSaved={(_, date) => {
            setBookingId(null);
            navigate(
              `/spa/appointments?customer=${encodeURIComponent(bookingId)}&date=${date}&view=list`,
            );
          }}
        />
      )}
    </>
  );
}
