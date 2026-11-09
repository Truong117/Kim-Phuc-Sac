import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { useTheme } from "@/context/ThemeContext";
import type {
  FacebookMetric,
  FacebookTrendPoint,
} from "@/types/facebookAnalytics";
import { analyticsNumber } from "@/utils/facebookAnalytics";
import { formatDateOnly } from "@/utils/dateOnly";

interface Props {
  points: FacebookTrendPoint[];
  metric: FacebookMetric;
  selectedDate?: string;
  onSelectDate?: (date: string) => void;
  counts?: Record<string, number>;
}
export default function AnalyticsTrend({
  points,
  metric,
  selectedDate,
  onSelectDate,
  counts,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const canvas = useRef<HTMLCanvasElement>(null);
  const geometry = useRef({ start: 48, span: 1 });
  const [hover, setHover] = useState<number | null>(null);
  const { theme } = useTheme();
  useEffect(() => {
    const el = canvas.current;
    if (!el) return;
    function paint() {
      if (!el) return;
      const ctx = el.getContext("2d");
      if (!ctx) return;
      const width = el.clientWidth,
        height = 230,
        ratio = window.devicePixelRatio || 1;
      el.width = width * ratio;
      el.height = height * ratio;
      ctx.scale(ratio, ratio);
      const style = getComputedStyle(el);
      const color =
        style.getPropertyValue("--color-kps-primary").trim() || "#8B633A";
      const muted = theme === "dark" ? "#98A2B3" : "#667085";
      const valid = points.flatMap((p) =>
        p[metric] == null ? [] : [p[metric] as number],
      );
      const min = Math.min(0, ...valid),
        max = Math.max(1, ...valid),
        span = max - min;
      const ticks = [
        ...new Set(
          Array.from({ length: 5 }, (_, i) => Math.round(min + (span * i) / 4)),
        ),
      ];
      ctx.font = "11px sans-serif";
      const start = Math.max(
        40,
        ...ticks.map((v) => ctx.measureText(analyticsNumber(v)).width + 12),
      );
      const plot = Math.max(1, width - start - 12),
        slot = plot / Math.max(1, points.length);
      geometry.current = { start, span: plot };
      const x = (i: number) => start + slot * (i + 0.5);
      const y = (v: number) => 22 + ((max - v) / span) * 162;
      ctx.textAlign = "end";
      for (const v of ticks) {
        ctx.strokeStyle = theme === "dark" ? "#344054" : "#EAECF0";
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(start, y(v));
        ctx.lineTo(width - 12, y(v));
        ctx.stroke();
        ctx.fillStyle = muted;
        ctx.fillText(analyticsNumber(v), start - 8, y(v) + 4);
      }
      const labelEvery = Math.max(
        1,
        Math.ceil(points.length / Math.max(2, Math.floor(plot / 55))),
      );
      points.forEach((p, i) => {
        const v = p[metric],
          active = p.date === selectedDate;
        ctx.textAlign = "center";
        ctx.fillStyle = color;
        ctx.globalAlpha = !selectedDate || active ? 1 : 0.55;
        if (v != null) {
          const barWidth = Math.max(1, Math.min(30, slot * 0.6)),
            top = Math.min(y(v), y(0));
          ctx.fillRect(
            x(i) - barWidth / 2,
            top,
            barWidth,
            Math.max(2, Math.abs(y(v) - y(0))),
          );
          ctx.globalAlpha = 1;
          if (slot >= 32) {
            ctx.fillStyle = muted;
            ctx.fillText(
              analyticsNumber(v),
              x(i),
              v >= 0 ? top - 6 : y(v) + 14,
            );
          }
        } else {
          ctx.globalAlpha = 1;
          ctx.fillStyle = muted;
          ctx.fillText("—", x(i), y(0) - 6);
        }
        ctx.globalAlpha = 1;
        if (i === 0 || i === points.length - 1 || i % labelEvery === 0) {
          ctx.fillStyle = active ? color : muted;
          ctx.fillText(formatDateOnly(p.date).slice(0, 5), x(i), 214);
        }
      });
    }
    const observer = new ResizeObserver(paint);
    observer.observe(el);
    paint();
    return () => observer.disconnect();
  }, [points, metric, theme, selectedDate]);
  function indexAt(offset: number) {
    return Math.max(
      0,
      Math.min(
        points.length - 1,
        Math.floor(
          ((offset - geometry.current.start) / geometry.current.span) *
            points.length,
        ),
      ),
    );
  }
  const active = hover == null ? null : points[hover];
  return (
    <div className="relative min-w-0">
      <canvas
        ref={canvas}
        role="img"
        aria-label={t("chartLabel", { metric: t("metrics." + metric) })}
        className="h-60 w-full"
        onPointerMove={(e) => setHover(indexAt(e.nativeEvent.offsetX))}
        onPointerLeave={() => setHover(null)}
        onClick={
          onSelectDate
            ? (e) => {
                const p = points[indexAt(e.nativeEvent.offsetX)];
                if (p) onSelectDate(p.date);
              }
            : undefined
        }
      />
      {active && (
        <div className="pointer-events-none absolute end-2 top-0 max-w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs text-gray-700 shadow-theme-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
          {formatDateOnly(active.date)} · {analyticsNumber(active[metric])}
          {counts && (
            <span className="ms-2">
              {t("review.contributorCount", {
                count: counts[active.date] ?? 0,
              })}
            </span>
          )}
        </div>
      )}
      {onSelectDate && (
        <label className="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
          <span>{t("review.selectDay")}</span>
          <select
            aria-label={t("review.selectDay")}
            value={selectedDate ?? ""}
            onChange={(e) => onSelectDate(e.target.value)}
            className="h-10 max-w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
          >
            {points.map((p) => (
              <option key={p.date} value={p.date}>
                {formatDateOnly(p.date)} ·{" "}
                {p[metric] == null
                  ? t("review.missingPair")
                  : analyticsNumber(p[metric])}
              </option>
            ))}
          </select>
        </label>
      )}
      <details className="text-xs text-gray-500 dark:text-gray-400">
        <summary className="cursor-pointer">{t("chartTable")}</summary>
        <div className="mt-2 max-h-60 overflow-auto">
          <table className="w-full text-start">
            <thead>
              <tr>
                <th scope="col" className="py-2">
                  {t("review.laterSampleDate")}
                </th>
                <th scope="col">{t("metrics." + metric)}</th>
                {counts && <th scope="col">{t("review.contributors")}</th>}
              </tr>
            </thead>
            <tbody>
              {points.map((p) => (
                <tr key={p.date}>
                  <td className="py-2">
                    {onSelectDate ? (
                      <button
                        type="button"
                        onClick={() => onSelectDate(p.date)}
                        className="text-kps-primary underline dark:text-brand-300"
                      >
                        {formatDateOnly(p.date)}
                      </button>
                    ) : (
                      formatDateOnly(p.date)
                    )}
                  </td>
                  <td>{analyticsNumber(p[metric])}</td>
                  {counts && (
                    <td>{p[metric] == null ? "—" : (counts[p.date] ?? 0)}</td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </div>
  );
}
