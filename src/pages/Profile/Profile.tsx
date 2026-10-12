import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import ProfilePersonalInfo from "@/components/profile/ProfilePersonalInfo";
import ProfileWorkInfo from "@/components/profile/ProfileWorkInfo";
import { useAuth } from "@/hooks/useAuth";
import { useTranslation } from "react-i18next";

export default function Profile() {
  const { t } = useTranslation("common", { keyPrefix: "profile" });
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
        <ProfilePersonalInfo user={currentUser} />
        <ProfileWorkInfo user={currentUser} />
      </div>
    </>
  );
}
