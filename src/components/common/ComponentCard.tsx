interface ComponentCardProps {
  title: string;
  children: React.ReactNode;
  className?: string; // Additional custom classes for styling
  desc?: string; // Description text
  compact?: boolean;
}

const ComponentCard: React.FC<ComponentCardProps> = ({
  title,
  children,
  className = "",
  desc = "",
  compact = false,
}) => {
  return (
    <div
      className={`rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] ${className}`}
    >
      {/* Card Header */}
      <div className={compact ? "px-5 pt-4 pb-2" : "px-6 py-5"}>
        <h3 className="text-base font-semibold text-gray-800 dark:text-white/90">
          {title}
        </h3>
        {desc && (
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {desc}
          </p>
        )}
      </div>

      {/* Card Body */}
      <div
        className={
          compact
            ? "px-5 pt-2 pb-5"
            : "border-t border-gray-100 p-4 dark:border-gray-800 sm:p-6"
        }
      >
        <div className={compact ? "space-y-4" : "space-y-6"}>{children}</div>
      </div>
    </div>
  );
};

export default ComponentCard;
