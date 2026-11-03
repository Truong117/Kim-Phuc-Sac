import { useEffect, useRef, type ReactNode } from "react";
import { syncReportTextAreas } from "@/utils/syncReportTextAreas";
import { cn } from "@/utils";

export default function ReportFieldRow({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (ref.current) return syncReportTextAreas(ref.current);
  }, []);
  return (
    <div
      ref={ref}
      className={cn(
        "grid grid-cols-1 gap-4 lg:grid-cols-2 [&_textarea]:min-h-16 [&_textarea]:resize-y",
        className,
      )}
    >
      {children}
    </div>
  );
}
