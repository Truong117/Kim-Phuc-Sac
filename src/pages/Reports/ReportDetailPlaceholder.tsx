import ComponentCard from "@/components/common/ComponentCard";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import Button from "@/components/ui/button/Button";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

export default function ReportDetailPlaceholder() {
  const { t } = useTranslation("common", { keyPrefix: "reportDetail" });
  const navigate = useNavigate();

  return (
    <>
      <PageMeta
        title={`${t("title")} | KIM PHỤC SẮC`}
        description={t("metaDescription")}
      />
      <PageBreadCrumb pageTitle={t("title")} />

      <ComponentCard title={t("title")}>
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("description")}
        </p>
        <Button
          type="button"
          size="sm"
          className="!bg-sidebar-accent hover:opacity-90"
          onClick={() => navigate("/reports")}
        >
          {t("back")}
        </Button>
      </ComponentCard>
    </>
  );
}
