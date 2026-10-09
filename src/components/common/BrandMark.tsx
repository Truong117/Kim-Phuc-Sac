import { cn } from "@/utils";

interface BrandMarkProps {
  compact?: boolean;
  className?: string;
  inverted?: boolean;
  size?: "default" | "large";
}

export default function BrandMark({
  compact = false,
  className,
  inverted = false,
  size = "default",
}: BrandMarkProps) {
  const isLarge = size === "large";

  return (
    <div
      className={cn(
        "flex items-center",
        isLarge ? "gap-3 sm:gap-4" : "gap-3",
        className,
      )}
    >
      <img
        src={
          inverted ? "/images/logo/logo trang.png" : "/images/logo/logo mau.png"
        }
        alt="Logo Kim Phục Sắc"
        className={cn(
          "shrink-0 object-contain",
          isLarge ? "size-14 sm:size-20" : "size-12",
        )}
      />

      {!compact && (
        <span className={cn("min-w-0", isLarge ? "leading-tight" : "leading-none")}>
          <span
            className={cn(
              "block font-bold whitespace-nowrap",
              isLarge
                ? "text-lg tracking-[0.08em] sm:text-2xl"
                : "text-base tracking-wide",
              inverted ? "text-white" : "text-gray-900 dark:text-white",
            )}
          >
            KIM PHỤC SẮC
          </span>
          <span
            className={cn(
              "block font-medium uppercase",
              isLarge
                ? "mt-2 text-sm font-semibold tracking-[0.2em]"
                : "mt-1.5 text-theme-xs tracking-[0.18em]",
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
