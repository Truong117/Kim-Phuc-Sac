import { useAuthorization } from "@/hooks/useAuthorization";
import { workspaceForPath } from "@/utils/workspace";
import { Link, useLocation } from "react-router";
import { useTranslation } from "react-i18next";
import type { ReactNode } from "react";
import { cn } from "@/utils";

interface BreadcrumbProps {
  pageTitle: string;
  headingLevel?: 1 | 2;
  className?: string;
  description?: string;
  eyebrow?: string;
  actions?: ReactNode;
  spa?: boolean;
}

const PageBreadcrumb: React.FC<BreadcrumbProps> = ({
  pageTitle,
  headingLevel = 2,
  className,
  description,
  eyebrow,
  actions,
  spa = false,
}) => {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const { workspaces } = useAuthorization();
  const home =
    workspaces.find((item) => item.id === workspaceForPath(pathname))?.home ??
    "/";
  const Heading = headingLevel === 1 ? "h1" : "h2";

  return (
    <div
      className={cn(
        "mb-6 flex flex-wrap items-center justify-between gap-3",
        className,
      )}
    >
      <div className={spa ? "min-w-0 flex-1 basis-64" : "min-w-0"}>
        {eyebrow && (
          <p className="mb-2 text-theme-xs font-semibold tracking-widest text-gray-500 uppercase dark:text-gray-400">
            {eyebrow}
          </p>
        )}
        <Heading
          className={
            spa
              ? "text-title-sm font-bold tracking-tight text-gray-900 dark:text-white"
              : "text-xl font-bold text-gray-800 dark:text-white/90"
          }
          x-text="pageName"
        >
          {pageTitle}
        </Heading>
        {description && (
          <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-500 dark:text-gray-400">
            {description}
          </p>
        )}
      </div>
      {spa ? (
        actions
      ) : (
        <nav>
          <ol className="flex items-center gap-1.5">
            <li>
              <Link
                className="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400"
                to={home}
              >
                {t("common.home")}
                <svg
                  className="stroke-current rtl:rotate-180"
                  width="17"
                  height="16"
                  viewBox="0 0 17 16"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                >
                  <path
                    d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366"
                    stroke=""
                    strokeWidth="1.2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </Link>
            </li>
            <li className="text-sm text-gray-800 dark:text-white/90">
              {pageTitle}
            </li>
          </ol>
        </nav>
      )}
    </div>
  );
};

export default PageBreadcrumb;
