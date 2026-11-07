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
}
export default function AnalyticsTrend({ points, metric }: Props) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const canvas = useRef<HTMLCanvasElement>(null);
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
      const x = (i: number) =>
        48 + (i * (width - 68)) / Math.max(1, points.length - 1);
      const y = (value: number) => 18 + ((max - value) / span) * 167;
      ctx.font = "11px sans-serif";
      ctx.fillStyle = muted;
      ctx.lineWidth = 1;
      for (let i = 0; i < 4; i++) {
        const v = min + (span * i) / 3;
        ctx.strokeStyle = theme === "dark" ? "#344054" : "#EAECF0";
        ctx.beginPath();
        ctx.moveTo(44, y(v));
        ctx.lineTo(width - 16, y(v));
        ctx.stroke();
        ctx.fillStyle = muted;
        ctx.fillText(analyticsNumber(Math.round(v)), 0, y(v) + 4);
      }
      ctx.strokeStyle = color;
      ctx.fillStyle = color;
      ctx.lineWidth = 2;
      let open = false;
      ctx.beginPath();
      points.forEach((point, i) => {
        const value = point[metric];
        if (value == null) {
          open = false;
          return;
        }
        if (open) ctx.lineTo(x(i), y(value));
        else ctx.moveTo(x(i), y(value));
        open = true;
      });
      ctx.stroke();
      points.forEach((point, i) => {
        const value = point[metric];
        if (value != null) {
          ctx.beginPath();
          ctx.arc(x(i), y(value), 3, 0, Math.PI * 2);
          ctx.fill();
        }
        if (
          i === 0 ||
          i === points.length - 1 ||
          i % Math.max(1, Math.ceil(points.length / 5)) === 0
        ) {
          ctx.fillStyle = muted;
          ctx.textAlign = "center";
          ctx.fillText(formatDateOnly(point.date).slice(0, 5), x(i), 212);
          ctx.fillStyle = color;
        }
      });
    }
    const observer = new ResizeObserver(paint);
    observer.observe(el);
    paint();
    return () => observer.disconnect();
  }, [points, metric, theme]);
  const active = hover == null ? null : points[hover];
  return (
    <div className="relative">
      <canvas
        ref={canvas}
        role="img"
        aria-label={t("chartLabel", { metric: t(`metrics.${metric}`) })}
        className="h-60 w-full"
        onPointerMove={(e) => {
          const width = e.currentTarget.clientWidth;
          setHover(
            Math.max(
              0,
              Math.min(
                points.length - 1,
                Math.round(
                  ((e.nativeEvent.offsetX - 48) / Math.max(1, width - 68)) *
                    (points.length - 1),
                ),
              ),
            ),
          );
        }}
        onPointerLeave={() => setHover(null)}
      />
      {active && (
        <div className="pointer-events-none absolute end-3 top-0 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs text-gray-700 shadow-theme-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
          {formatDateOnly(active.date)} · {analyticsNumber(active[metric])}
        </div>
      )}
      <details className="text-xs text-gray-500 dark:text-gray-400">
        <summary className="cursor-pointer">{t("chartTable")}</summary>
        <div className="mt-2 max-h-48 overflow-auto">
          <table className="w-full text-start">
            <thead>
              <tr>
                <th scope="col">{t("date")}</th>
                <th scope="col">{t(`metrics.${metric}`)}</th>
              </tr>
            </thead>
            <tbody>
              {points.map((p) => (
                <tr key={p.date}>
                  <td>{formatDateOnly(p.date)}</td>
                  <td>{analyticsNumber(p[metric])}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </div>
  );
}
