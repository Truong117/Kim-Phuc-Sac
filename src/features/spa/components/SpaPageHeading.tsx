import type { ReactNode } from "react";
import { useTranslation } from "react-i18next";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import type { SpaNavigationKey } from "../config";

export default function SpaPageHeading({
  section,
  actions,
}: {
  section: SpaNavigationKey;
  actions?: ReactNode;
}) {
  const { t } = useTranslation();
  return (
    <PageBreadCrumb
      spa
      pageTitle={t(`spa.navigation.${section}`)}
      eyebrow={t(`spa.concept.eyebrows.${section}`)}
      description={t(`spa.concept.descriptions.${section}`)}
      actions={actions}
    />
  );
}
