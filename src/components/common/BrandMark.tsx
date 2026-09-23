import { cn } from "@/utils";

interface BrandMarkProps {
  compact?: boolean;
  className?: string;
  inverted?: boolean;
}

export default function BrandMark({
  compact = false,
  className,
  inverted = false,
}: BrandMarkProps) {
  return (
    <div className={cn("flex items-center gap-3", className)}>
      <img
        src="/images/logo/logo trang.png"
        alt="Logo Kim Phục Sắc"
        className="size-12 shrink-0 object-contain"
      />

      {!compact && (
        <span className="min-w-0 leading-none">
          <span
            className={cn(
              "block text-base font-bold tracking-wide whitespace-nowrap",
              inverted ? "text-white" : "text-gray-900 dark:text-white",
            )}
          >
            KIM PHỤC SẮC
          </span>
          <span
            className={cn(
              "mt-1.5 block text-theme-xs font-medium tracking-[0.18em] uppercase",
              inverted ? "text-white/75" : "text-gray-500 dark:text-gray-400",
            )}
          >
            Internal System
          </span>
        </span>
      )}
    </div>
  );
}
