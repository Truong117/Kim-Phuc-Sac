import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import type {
  CrmActivityKind,
  CrmCustomerDetail,
  CrmOpportunity,
} from "@/services/crmService";

export type CrmAction =
  | "new"
  | "assignment"
  | "stage"
  | Extract<CrmActivityKind, "OPPORTUNITY" | "CONSULTATION" | "FOLLOW_UP">;

export default function CrmCustomerDetails({
  customer,
  open,
}: {
  customer: CrmCustomerDetail;
  open: (action: CrmAction, opportunity?: CrmOpportunity) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "crm" });
  return (
    <div className="space-y-4">
      <ComponentCard title={customer.name} desc={customer.code}>
        <div className="flex flex-wrap gap-2">
          {(customer.can_consult ?? customer.can_update) && (
            <Button
              size="sm"
              type="button"
              onClick={() => open("CONSULTATION")}
            >
              {t("actions.CONSULTATION")}
            </Button>
          )}
          {customer.can_update &&
            (["OPPORTUNITY", "FOLLOW_UP"] as const).map((kind) => (
              <Button
                key={kind}
                size="sm"
                type="button"
                variant="outline"
                onClick={() => open(kind)}
              >
                {t("actions." + kind)}
              </Button>
            ))}
          {customer.can_assign && (
            <Button
              size="sm"
              type="button"
              variant="outline"
              onClick={() => open("assignment")}
            >
              {t("actions.assignment")}
            </Button>
          )}
        </div>
        <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-gray-500 dark:text-gray-400">{t("owner")}</dt>
            <dd className="mt-1 font-medium text-gray-900 dark:text-gray-100">
              {customer.owner_name || t("unassigned")}
            </dd>
          </div>
          <div>
            <dt className="text-gray-500 dark:text-gray-400">{t("contact")}</dt>
            <dd className="mt-1 break-all text-gray-800 dark:text-gray-200">
              {customer.contacts.length
                ? customer.contacts.map(({ channel, value }, i) => (
                    <p key={i}>
                      {t("channels." + channel, { defaultValue: channel })}
                      {value && " · " + value}
                    </p>
                  ))
                : t("none")}
            </dd>
          </div>
        </dl>
      </ComponentCard>
      <ComponentCard title={t("opportunities")}>
        <ul className="divide-y divide-gray-200 dark:divide-gray-700">
          {customer.opportunities.map((opportunity) => (
            <li
              key={opportunity.id}
              className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
            >
              <div className="min-w-0">
                <p className="font-medium break-words text-gray-900 dark:text-gray-100">
                  {opportunity.title}
                </p>
                <p className="text-gray-600 dark:text-gray-400">
                  {t("stages." + opportunity.stage)}
                  {opportunity.resolution && " · " + opportunity.resolution}
                </p>
              </div>
              {customer.can_update &&
                !["WON", "LOST"].includes(opportunity.stage) && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => open("stage", opportunity)}
                  >
                    {t("actions.stage")}
                  </Button>
                )}
            </li>
          ))}
        </ul>
        {!customer.opportunities.length && (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {t("emptyOpportunities")}
          </p>
        )}
      </ComponentCard>
      <ComponentCard title={t("activities")}>
        <ul className="divide-y divide-gray-200 dark:divide-gray-700">
          {customer.activities.map((activity) => (
            <li key={activity.id} className="space-y-1 py-2 text-sm">
              <p className="font-medium text-gray-900 dark:text-gray-100">
                {activity.title}
              </p>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {activity.business_date} · {activity.actor_name}
                {activity.due_on && " → " + activity.due_on}
              </p>
              {!!activity.services.length && (
                <p className="text-xs font-medium text-kps-primary dark:text-kps-primary-hover">
                  {activity.services.map(({ name }) => name).join(" · ")}
                </p>
              )}
              {activity.result && (
                <p className="break-words whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                  {activity.kind === "INTAKE"
                    ? t("channels." + activity.result)
                    : activity.result}
                </p>
              )}
            </li>
          ))}
        </ul>
        {!customer.activities.length && (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {t("emptyActivities")}
          </p>
        )}
      </ComponentCard>
    </div>
  );
}
