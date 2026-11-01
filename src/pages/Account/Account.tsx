import AccountPasswordSection from "@/components/account/AccountPasswordSection";
import AccountSummary from "@/components/account/AccountSummary";
import AccountNavigation from "@/components/account/AccountNavigation";
import AccountEmailSection from "@/components/account/AccountEmailSection";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import { useAuth } from "@/hooks/useAuth";
import { useTranslation } from "react-i18next";

export default function Account() {
  const { t } = useTranslation("common", { keyPrefix: "account" });
  const { currentUser } = useAuth();

  if (!currentUser) return null;

  return (
    <>
      <PageMeta title={t("metaTitle")} description={t("metaDescription")} />
      <PageBreadCrumb pageTitle={t("title")} />
      <p className="-mt-4 mb-6 text-theme-sm text-gray-500 dark:text-gray-400">
        {t("subtitle")}
      </p>

      <div className="mx-auto max-w-5xl space-y-4">
        <AccountNavigation />
        <AccountSummary user={currentUser} />
        <AccountEmailSection key={String(currentUser.id)} />
        <AccountPasswordSection />
      </div>
    </>
  );
}
