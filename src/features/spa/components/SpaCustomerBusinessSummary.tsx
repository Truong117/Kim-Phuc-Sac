import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import { useAuthorization } from "@/hooks/useAuthorization";
import { canOpenWorkspacePath } from "@/utils/workspace";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import { spaInvoiceBalance, spaMoney } from "../businessSelectors";
export default function SpaCustomerBusinessSummary({
  customerId,
}: {
  customerId: string;
}) {
  const { t } = useTranslation();
  const { snapshot } = useSpaPreview();
  const { workspaces } = useAuthorization();
  const packages = snapshot.packages.filter((p) => p.customerId === customerId);
  const invoices = snapshot.invoices.filter((i) => i.customerId === customerId);
  return (
    <div className="space-y-3 rounded-xl bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200">
      <h4 className="font-medium">{t("spa.navigation.treatments")}</h4>
      {packages.length ? (
        packages.map((p) => (
          <p key={p.id}>
            {p.name} ·{" "}
            {t("spa.business.sessionBalance", { used: p.used, total: p.total })}{" "}
            · {p.expires}
          </p>
        ))
      ) : (
        <p>{t("spa.business.empty")}</p>
      )}
      {canOpenWorkspacePath(workspaces, "/spa/cashier") && (
        <>
          <p>
            {t("spa.business.outstanding")}:{" "}
            <strong>
              {spaMoney(
                invoices.reduce(
                  (s, i) => s + spaInvoiceBalance(snapshot, i.id),
                  0,
                ),
              )}
            </strong>
          </p>
          <Link
            to="/spa/cashier"
            className="inline-flex min-h-11 items-center font-medium text-kps-primary underline dark:text-gray-200"
          >
            {t("spa.navigation.cashier")}
          </Link>
        </>
      )}
    </div>
  );
}
