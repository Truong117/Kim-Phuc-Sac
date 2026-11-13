import { useEffect, useRef } from "react";
import { useTranslation } from "react-i18next";
import { useTheme } from "@/context/ThemeContext";

export default function SpaActivityChart({
  points,
}: {
  points: { label: string; count: number }[];
}) {
  const { t } = useTranslation();
  const { theme } = useTheme();
  const ref = useRef<HTMLCanvasElement>(null);
  useEffect(() => {
    const canvas = ref.current;
    if (!canvas) return;
    const draw = () => {
      const context = canvas.getContext("2d");
      if (!context) return;
      const width = canvas.clientWidth,
        height = 220,
        ratio = window.devicePixelRatio || 1;
      canvas.width = width * ratio;
      canvas.height = height * ratio;
      context.scale(ratio, ratio);
      const style = getComputedStyle(canvas),
        text = getComputedStyle(canvas.parentElement!).color;
      const top = 16,
        bottom = 32,
        start = 28,
        end = Math.max(start, width - 16);
      const max = Math.max(3, ...points.map((point) => point.count));
      const x = (index: number) =>
        start + ((end - start) * index) / Math.max(1, points.length - 1);
      const y = (value: number) =>
        height - bottom - ((height - top - bottom) * value) / max;
      context.font = "11px sans-serif";
      context.fillStyle = text;
      for (let index = 0; index <= 3; index++) {
        const value = Math.round((max * index) / 3),
          position = y(value);
        context.strokeStyle = style.borderColor;
        context.setLineDash([3, 4]);
        context.beginPath();
        context.moveTo(start, position);
        context.lineTo(end, position);
        context.stroke();
        context.fillText(String(value), 4, position + 4);
      }
      const labelEvery = Math.max(
        1,
        Math.ceil(points.length / Math.max(2, width / 65)),
      );
      context.textAlign = "center";
      points.forEach((point, index) => {
        if (index % labelEvery === 0)
          context.fillText(point.label, x(index), height - 8);
      });
      context.setLineDash([]);
      context.beginPath();
      points.forEach((point, index) =>
        index
          ? context.lineTo(x(index), y(point.count))
          : context.moveTo(x(index), y(point.count)),
      );
      context.lineWidth = 2;
      context.strokeStyle = style.color;
      context.stroke();
      context.lineTo(x(points.length - 1), y(0));
      context.lineTo(x(0), y(0));
      context.closePath();
      const gradient = context.createLinearGradient(0, top, 0, height - bottom);
      gradient.addColorStop(0, style.color);
      gradient.addColorStop(1, "transparent");
      context.globalAlpha = 0.12;
      context.fillStyle = gradient;
      context.fill();
      context.globalAlpha = 1;
      points.forEach((point, index) => {
        context.beginPath();
        context.arc(x(index), y(point.count), 3, 0, 2 * Math.PI);
        context.fillStyle = style.color;
        context.fill();
      });
    };
    const resize = new ResizeObserver(draw);
    resize.observe(canvas);
    draw();
    return () => resize.disconnect();
  }, [points, theme]);
  return (
    <figure className="text-gray-500 dark:text-gray-400">
      <canvas
        ref={ref}
        role="img"
        aria-label={t("spa.concept.activityChart")}
        className="h-55 w-full border-gray-100 text-kps-primary dark:border-gray-800 dark:text-kps-primary-hover"
      />
      <figcaption className="sr-only">
        {points.map((point) => `${point.label}: ${point.count}`).join("; ")}
      </figcaption>
    </figure>
  );
}
