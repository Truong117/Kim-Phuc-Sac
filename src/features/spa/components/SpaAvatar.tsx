export default function SpaAvatar({ name }: { name: string }) {
  return (
    <span
      aria-hidden="true"
      className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-kps-primary/10 text-theme-xs font-semibold text-kps-primary-hover dark:bg-kps-primary/20 dark:text-gray-200"
    >
      {name
        .trim()
        .split(/\s+/)
        .slice(-2)
        .map((part) => part[0])
        .join("")}
    </span>
  );
}
