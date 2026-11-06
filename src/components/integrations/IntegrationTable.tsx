import { useTranslation } from "react-i18next";
import {
  EyeIcon,
  PencilIcon,
  TrashBinIcon,
  PlugInIcon,
  DocsIcon,
} from "@/icons";
import type { IntegrationEntry, IntegrationTab } from "./model";
interface Props {
  entries: IntegrationEntry[];
  kind: IntegrationTab;
  canManage: boolean;
  onAction: (
    action: "detail" | "edit" | "delete",
    entry: IntegrationEntry,
  ) => void;
}
export default function IntegrationTable({
  entries,
  kind,
  onAction,
  canManage,
}: Props) {
  const { t } = useTranslation();
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-start text-sm">
        <thead className="bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
          <tr>
            {kind === "social" && (
              <th scope="col" className="px-5 py-4 text-start font-medium">
                {t("integrationsUi.platform")}
              </th>
            )}
            <th scope="col" className="px-5 py-4 text-start font-medium">
              {t(
                `integrationsUi.${kind === "drive" ? "folderName" : "channelName"}`,
              )}
            </th>
            <th scope="col" className="px-5 py-4 text-start font-medium">
              {t("integrationsUi.owner")}
            </th>
            <th scope="col" className="px-5 py-4 text-start font-medium">
              {t("integrationsUi.status")}
            </th>
            <th scope="col" className="px-5 py-4 text-end font-medium">
              {t("integrationsUi.actions")}
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
          {entries.map((entry) => (
            <tr
              key={entry.id}
              className="hover:bg-gray-50/70 dark:hover:bg-white/3"
            >
              {kind === "social" && (
                <td className="px-5 py-5">
                  <span className="inline-flex items-center gap-2 whitespace-nowrap font-medium text-gray-700 dark:text-gray-200">
                    <PlugInIcon className="size-4 text-kps-primary" />
                    {t(`integrationsUi.platforms.${entry.platform}`)}
                  </span>
                </td>
              )}
              <td className="px-5 py-5">
                <div className="flex items-start gap-3">
                  {kind === "drive" && (
                    <DocsIcon className="mt-1 size-5 shrink-0 text-kps-primary" />
                  )}
                  <div className="min-w-40">
                    <div className="font-semibold text-gray-800 dark:text-white/90">
                      {entry.name}
                    </div>
                    <div className="mt-1 max-w-64 truncate text-xs text-gray-500 dark:text-gray-400">
                      {entry.url}
                    </div>
                  </div>
                </div>
              </td>
              <td className="px-5 py-5 text-gray-600 dark:text-gray-300">
                {entry.owner}
              </td>
              <td className="px-5 py-5">
                <span
                  className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ${entry.enabled ? "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400" : "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400"}`}
                >
                  {t(
                    entry.enabled &&
                      entry.connection_status &&
                      entry.connection_status !== "not_connected"
                      ? `integrationsUi.social.status.${entry.connection_status}`
                      : `integrationsUi.${entry.enabled ? "unverified" : "paused"}`,
                  )}
                </span>
              </td>
              <td className="px-5 py-5">
                <div className="flex justify-end gap-1">
                  {(
                    [
                      ["detail", EyeIcon],
                      ["edit", PencilIcon],
                      ["delete", TrashBinIcon],
                    ] as const
                  )
                    .filter(([action]) => action === "detail" || canManage)
                    .map(([action, Icon]) => (
                      <button
                        key={action}
                        type="button"
                        onClick={() => onAction(action, entry)}
                        aria-label={`${t(`integrationsUi.${action}`)}: ${entry.name}`}
                        title={t(`integrationsUi.${action}`)}
                        className={`rounded-lg p-2 focus-visible:outline-2 focus-visible:outline-kps-primary ${action === "delete" ? "text-error-500 hover:bg-error-50 dark:hover:bg-error-500/10" : "text-gray-500 hover:bg-gray-100 hover:text-kps-primary dark:text-gray-400 dark:hover:bg-gray-800"}`}
                      >
                        <Icon className="size-5" />
                      </button>
                    ))}
                </div>
              </td>
            </tr>
          ))}
          {entries.length === 0 && (
            <tr>
              <td
                colSpan={kind === "social" ? 5 : 4}
                className="px-5 py-16 text-center text-gray-500 dark:text-gray-400"
              >
                {t("integrationsUi.empty")}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}
