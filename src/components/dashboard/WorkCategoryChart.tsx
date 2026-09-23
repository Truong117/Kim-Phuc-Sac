import ComponentCard from "@/components/common/ComponentCard";
import type { WorkCategoryStat } from "@/types/dashboard";
import type { ApexOptions } from "apexcharts";
import { lazy, Suspense } from "react";
import { useTranslation } from "react-i18next";

const ReactApexChart = lazy(() => import("react-apexcharts"));

interface WorkCategoryChartProps {
  data: WorkCategoryStat[];
}

const categoryColors = [
  { chart: "#b72c54", indicator: "bg-brand-500" },
  { chart: "#0ba5ec", indicator: "bg-blue-light-500" },
  { chart: "#7a5af8", indicator: "bg-theme-purple-500" },
  { chart: "#f79009", indicator: "bg-warning-500" },
  { chart: "#667085", indicator: "bg-gray-500" },
] as const;

export default function WorkCategoryChart({ data }: WorkCategoryChartProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "managementDashboard",
  });
  const total = data.reduce((sum, item) => sum + item.count, 0);
  const getPercentage = (count: number) =>
    total === 0 ? 0 : Math.round((count / total) * 100);

  const options: ApexOptions = {
    chart: {
      type: "donut",
      fontFamily: "Outfit, sans-serif",
    },
    colors: categoryColors.map((color) => color.chart),
    labels: data.map((item) => item.category),
    dataLabels: { enabled: false },
    legend: { show: false },
    stroke: {
      colors: ["#ffffff"],
      width: 4,
    },
    plotOptions: {
      pie: {
        donut: {
          size: "68%",
          labels: {
            show: true,
            name: { show: false },
            value: { show: false },
            total: {
              show: true,
              label: t("category.total"),
              formatter: () => String(total),
            },
          },
        },
      },
    },
    tooltip: {
      y: {
        formatter: (value) => `${value} (${getPercentage(value)}%)`,
      },
    },
  };

  return (
    <ComponentCard title={t("category.title")} className="h-full">
      <div className="grid items-center gap-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] xl:grid-cols-1 2xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div className="mx-auto w-full max-w-65">
          <Suspense
            fallback={
              <div className="flex h-65 items-center justify-center text-theme-sm text-gray-500 dark:text-gray-400">
                {t("chartLoading")}
              </div>
            }
          >
            <ReactApexChart
              options={options}
              series={data.map((item) => item.count)}
              type="donut"
              height={260}
            />
          </Suspense>
        </div>

        <ul className="space-y-3">
          {data.map((item, index) => (
            <li
              key={item.category}
              className="flex items-center justify-between gap-3 text-theme-sm"
            >
              <span className="flex min-w-0 items-center gap-2 text-gray-600 dark:text-gray-300">
                <span
                  className={`size-2.5 shrink-0 rounded-full ${categoryColors[index]?.indicator ?? "bg-gray-500"}`}
                />
                <span>{item.category}</span>
              </span>
              <span className="font-semibold text-gray-900 dark:text-white">
                {item.count} ({getPercentage(item.count)}%)
              </span>
            </li>
          ))}
        </ul>
      </div>
    </ComponentCard>
  );
}
