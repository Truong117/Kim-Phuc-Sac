import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
import type {
  WorkItemField,
  WorkItemFormData,
  WorkItemValidationErrors,
} from "@/types/reports";
import { useTranslation } from "react-i18next";
import WorkItemForm from "./WorkItemForm";

interface WorkItemListProps {
  items: WorkItemFormData[];
  errors: Record<string, WorkItemValidationErrors>;
  onItemChange: (
    clientId: string,
    changes: Partial<Omit<WorkItemFormData, "clientId">>,
  ) => void;
  onItemBlur: (clientId: string, field: WorkItemField) => void;
  onItemAdd: () => void;
  onItemDelete: (clientId: string) => void;
}

export default function WorkItemList({
  items,
  errors,
  onItemChange,
  onItemBlur,
  onItemAdd,
  onItemDelete,
}: WorkItemListProps) {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport" });
  const hasReachedLimit = items.length >= 50;

  return (
    <div className="space-y-5">
      {items.map((item, index) => (
        <WorkItemForm
          key={item.clientId}
          index={index}
          item={item}
          errors={errors[item.clientId]}
          canDelete={items.length > 1}
          onChange={onItemChange}
          onBlur={onItemBlur}
          onDelete={onItemDelete}
        />
      ))}

      <div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          startIcon={<PlusIcon className="size-5" />}
          disabled={hasReachedLimit}
          onClick={onItemAdd}
        >
          {t("addWorkItem")}
        </Button>
        {hasReachedLimit && (
          <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
            {t("workItemLimit")}
          </p>
        )}
      </div>
    </div>
  );
}
