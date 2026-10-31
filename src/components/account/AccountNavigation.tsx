import { NavLink } from "react-router";
import { useTranslation } from "react-i18next";

export default function AccountNavigation() {
  const { t } = useTranslation();
  const items = [
    {
      to: "/profile",
      label: t("profile.title"),
      description: t("account.navigation.profile"),
    },
    {
      to: "/account",
      label: t("account.title"),
      description: t("account.navigation.account"),
    },
  ];

  return (
    <nav
      aria-label={t("account.navigation.label")}
      className="grid gap-3 sm:grid-cols-2"
    >
      {items.map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          className={({ isActive }) =>
            `rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-none ${isActive ? "border-brand-300 bg-brand-50 dark:border-brand-700 dark:bg-brand-500/10" : "border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-gray-800"}`
          }
        >
          <span className="block text-theme-sm font-semibold text-gray-900 dark:text-white/90">
            {item.label}
          </span>
          <span className="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400">
            {item.description}
          </span>
        </NavLink>
      ))}
    </nav>
  );
}
