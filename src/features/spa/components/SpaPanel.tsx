import type { ReactNode } from "react";
export default function SpaPanel({
  title,
  description,
  action,
  children,
  className = "",
}: {
  title: string;
  description?: string;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section
      className={`min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-theme-xs dark:border-gray-800 dark:bg-gray-900 ${className}`}
    >
      <div className="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
        <div className="min-w-0">
          <h3 className="font-semibold text-gray-900 dark:text-white">
            {title}
          </h3>
          {description && (
            <p className="mt-1 text-theme-xs leading-5 text-gray-500 dark:text-gray-400">
              {description}
            </p>
          )}
        </div>
        <div className="shrink-0">{action}</div>
      </div>
      {children}
    </section>
  );
}
