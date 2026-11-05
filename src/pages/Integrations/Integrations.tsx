import { useIntegrations } from "@/hooks/useIntegrations";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import { DocsIcon, PlugInIcon, PlusIcon } from "@/icons";
import { useAuth } from "@/hooks/useAuth";
import { useModal } from "@/hooks/useModal";
import IntegrationTable from "@/components/integrations/IntegrationTable";
import IntegrationDialog, {
  type DialogMode,
} from "@/components/integrations/IntegrationDialog";
import {
  blankEntry,
  platforms,
  type IntegrationEntry,
  type IntegrationTab,
} from "@/components/integrations/model";

export default function Integrations() {
  const { currentUser } = useAuth();
  return <IntegrationWorkspace key={currentUser?.id ?? "guest"} />;
}
function IntegrationWorkspace() {
  const { t } = useTranslation();
  const { entries, owners, canManage, loading, error, reload, save, remove } =
    useIntegrations();
  const [tab, setTab] = useState<IntegrationTab>("drive");
  const [query, setQuery] = useState("");
  const [platform, setPlatform] = useState("all");
  const [message, setMessage] = useState("");
  const [dialog, setDialog] = useState<{
    mode: DialogMode;
    entry: IntegrationEntry;
  } | null>(null);
  const modal = useModal();
  const tabEntries = entries.filter((row) => row.kind === tab);
  const filtered = tabEntries.filter(
    (row) =>
      (platform === "all" || tab === "drive" || row.platform === platform) &&
      `${row.name} ${row.owner} ${row.url}`
        .toLocaleLowerCase("vi")
        .includes(query.trim().toLocaleLowerCase("vi")),
  );
  function open(mode: DialogMode, entry: IntegrationEntry) {
    setDialog({ mode, entry });
    modal.openModal();
  }
  return (
    <>
      <PageMeta
        title={t("integrationsUi.metaTitle")}
        description={t("integrationsUi.subtitle")}
      />
      <PageBreadCrumb pageTitle={t("integrationsUi.title")} />
      <div className="space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold text-gray-900 dark:text-white">
              {t("integrationsUi.title")}
            </h1>
            <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
              {t("integrationsUi.subtitle")}
            </p>
          </div>
          <span className="rounded-full border border-kps-primary/20 bg-kps-primary/5 px-3 py-1.5 text-xs font-medium text-kps-primary dark:bg-kps-primary/10">
            {t("integrationsUi.previewLabel")}
          </span>
        </div>
        <div className="rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm leading-relaxed text-warning-800 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-400">
          {t("integrationsUi.previewNotice")}
        </div>
        {error && (
          <p
            role="alert"
            className="text-sm text-error-600 dark:text-error-400"
          >
            {t("integrationsUi.loadError")}
          </p>
        )}
        {message && (
          <p
            role="status"
            className="rounded-xl bg-gray-100 px-4 py-3 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200"
          >
            {message}
          </p>
        )}
        <div
          className="flex gap-2 border-b border-gray-200 dark:border-gray-800"
          role="tablist"
          aria-label={t("integrationsUi.title")}
        >
          {(
            [
              ["drive", DocsIcon],
              ["social", PlugInIcon],
            ] as const
          ).map(([value, Icon], index) => (
            <button
              key={value}
              type="button"
              role="tab"
              id={`integration-tab-${value}`}
              aria-selected={tab === value}
              aria-controls={`integration-panel-${value}`}
              tabIndex={tab === value ? 0 : -1}
              onClick={() => {
                setTab(value);
                setQuery("");
                setPlatform("all");
                setMessage("");
              }}
              onKeyDown={(e) => {
                if (
                  ["ArrowLeft", "ArrowRight", "Home", "End"].includes(e.key)
                ) {
                  e.preventDefault();
                  const target =
                    e.key === "Home"
                      ? "drive"
                      : e.key === "End"
                        ? "social"
                        : index === 0
                          ? "social"
                          : "drive";
                  setTab(target);
                  setQuery("");
                  setPlatform("all");
                  document.getElementById(`integration-tab-${target}`)?.focus();
                }
              }}
              className={`flex items-center gap-2 border-b-2 px-5 py-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-kps-primary ${tab === value ? "border-kps-primary text-kps-primary" : "border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"}`}
            >
              <Icon className="size-5" />
              {t(`integrationsUi.tabs.${value}`)}
              <span className="rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                {entries.filter((row) => row.kind === value).length}
              </span>
            </button>
          ))}
        </div>
        <section
          role="tabpanel"
          id={`integration-panel-${tab}`}
          aria-labelledby={`integration-tab-${tab}`}
        >
          <ComponentCard
            title={t(`integrationsUi.${tab}Title`)}
            desc={t(`integrationsUi.${tab}Description`)}
          >
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex w-full flex-wrap gap-3 sm:w-auto">
                <div className="w-full sm:w-72">
                  <Input
                    aria-label={t("integrationsUi.search")}
                    placeholder={t("integrationsUi.search")}
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                  />
                </div>
                {tab === "social" && (
                  <select
                    aria-label={t("integrationsUi.filter")}
                    value={platform}
                    onChange={(e) => setPlatform(e.target.value)}
                    className="h-11 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                  >
                    <option value="all">
                      {t("integrationsUi.allPlatforms")}
                    </option>
                    {platforms.map((p) => (
                      <option key={p} value={p}>
                        {t(`integrationsUi.platforms.${p}`)}
                      </option>
                    ))}
                  </select>
                )}
              </div>
              {canManage && !loading && (
                <Button
                  variant="outline"
                  className="!bg-kps-primary !text-white !ring-0 hover:!bg-kps-primary-hover"
                  startIcon={<PlusIcon className="size-5" />}
                  onClick={() => open("add", blankEntry(tab))}
                >
                  {t(
                    `integrationsUi.${tab === "drive" ? "addFolder" : "addChannel"}`,
                  )}
                </Button>
              )}
              <Button variant="outline" disabled={loading} onClick={reload}>
                {t("integrationsUi.reload")}
              </Button>
            </div>
            {loading ? (
              <p
                role="status"
                className="text-sm text-gray-500 dark:text-gray-400"
              >
                {t("integrationsUi.loading")}
              </p>
            ) : (
              !error && (
                <IntegrationTable
                  entries={filtered}
                  kind={tab}
                  onAction={open}
                  canManage={canManage}
                />
              )
            )}
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t("integrationsUi.count", {
                shown: filtered.length,
                total: tabEntries.length,
              })}
            </p>
          </ComponentCard>
        </section>
      </div>
      {modal.isOpen && dialog && (
        <IntegrationDialog
          key={`${dialog.mode}-${dialog.entry.id}`}
          mode={dialog.mode}
          initial={dialog.entry}
          entries={entries}
          onClose={modal.closeModal}
          owners={owners}
          canManage={canManage}
          onSave={async (entry) => {
            await save(entry);
            modal.closeModal();
            setMessage(t("integrationsUi.saved"));
          }}
          onDelete={async () => {
            await remove(dialog.entry);
            modal.closeModal();
            setMessage(t("integrationsUi.deleted"));
          }}
        />
      )}
    </>
  );
}
