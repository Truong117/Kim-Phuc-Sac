import { spaFieldClasses } from "@/features/spa/config";
import { useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
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
  const [query, setQuery] = useState("");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [bookingId, setBookingId] = useState<string | null>(null);
  const [notice, setNotice] = useState(false);
  const editor = useModal();
  const profileRef = useRef<HTMLElement>(null);
  const revealProfile = () =>
    requestAnimationFrame(() => {
      if (window.innerWidth < 1280)
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
      <PageBreadCrumb pageTitle={t("spa.navigation.customers")} />
      <div className="space-y-5">
        <div className="flex flex-wrap items-end justify-between gap-3">
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
          <Button
            onClick={() => {
              setEditingId(null);
              editor.openModal();
            }}
            className="!bg-kps-primary hover:!bg-kps-primary-hover"
          >
            {t("spa.flow.newCustomer")}
          </Button>
        </div>
        {notice && (
          <p
            role="status"
            className="text-sm text-success-700 dark:text-success-400"
          >
            {t("spa.flow.saved")}
          </p>
        )}
        <div className="grid items-start gap-5 xl:grid-cols-3">
          <ComponentCard
            title={t("spa.flow.customerList")}
            className="xl:col-span-2"
            desc={t("spa.flow.customerCount", { count: customers.length })}
          >
            <div className="space-y-3">
              {customers.map((customer) => (
                <article
                  key={customer.id}
                  aria-label={customer.name}
                  className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
                >
                  <h3 className="font-semibold text-gray-900 dark:text-white">
                    {customer.name}
                  </h3>
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {customer.phone}
                  </p>
                  <div className="mt-3 flex flex-wrap gap-2">
                    <Button
                      variant="outline"
                      size="sm"
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
              ))}
              {!customers.length && (
                <p
                  role="status"
                  className="text-sm text-gray-500 dark:text-gray-400"
                >
                  {t("spa.flow.noCustomers")}
                </p>
              )}
            </div>
          </ComponentCard>
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
                              .map((task) =>
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
        </div>
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
              `/spa/appointments?customer=${encodeURIComponent(bookingId)}&date=${date}`,
            );
          }}
        />
      )}
    </>
  );
}
