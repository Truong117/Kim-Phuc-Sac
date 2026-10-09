import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { ManagedUser } from "@/types/userManagement";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";
import EmployeeStatusBadge from "./EmployeeStatusBadge";

interface EmployeeTableProps {
  users: ManagedUser[];
}

export default function EmployeeTable({ users }: EmployeeTableProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "userManagement.table",
  });

  return (
    <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-xs dark:border-gray-800 dark:bg-white/3">
      <div className="px-5 py-5 sm:px-6">
        <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
          {t("title")}
        </h2>
        <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
          {t("description")}
        </p>
      </div>

      <div className="max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
        <Table className="min-w-225 xl:min-w-full">
          <TableHeader>
            <TableRow className="border-b border-gray-100 dark:border-gray-800">
              {["name", "email", "department", "role", "status", "actions"].map(
                (column) => (
                  <TableCell
                    key={column}
                    isHeader
                    className="px-4 py-3 text-start text-theme-xs font-medium whitespace-nowrap text-gray-500 dark:text-gray-400"
                  >
                    {t(column)}
                  </TableCell>
                ),
              )}
            </TableRow>
          </TableHeader>

          <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
            {users.map((user) => (
              <TableRow
                key={user.id}
                className="transition-colors hover:bg-gray-50 dark:hover:bg-white/2"
              >
                <TableCell className="px-4 py-4 text-theme-sm font-medium whitespace-nowrap text-gray-800 dark:text-white/90">
                  {user.name}
                </TableCell>
                <TableCell className="px-4 py-4 text-theme-sm whitespace-nowrap text-gray-600 dark:text-gray-300">
                  {user.email}
                </TableCell>
                <TableCell className="px-4 py-4 text-theme-sm whitespace-nowrap text-gray-600 dark:text-gray-300">
                  {user.department?.name ?? t("notAssigned")}
                </TableCell>
                <TableCell className="px-4 py-4 text-theme-sm whitespace-nowrap text-gray-600 dark:text-gray-300">
                  {user.role?.name ?? t("notAssigned")}
                </TableCell>
                <TableCell className="px-4 py-4 whitespace-nowrap">
                  <EmployeeStatusBadge isActive={user.is_active} />
                </TableCell>
                <TableCell className="px-4 py-4 whitespace-nowrap">
                  <Link
                    to={`/employees/${user.id}`}
                    className="rounded text-theme-sm font-medium text-sidebar-accent transition-opacity hover:opacity-75 focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none dark:text-sidebar-selected"
                  >
                    {t("edit")}
                  </Link>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </section>
  );
}
