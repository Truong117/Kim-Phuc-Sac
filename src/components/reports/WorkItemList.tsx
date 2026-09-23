import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
import type {
  WorkItemFormData,
  WorkItemField,
  WorkItemValidationErrors,
} from "@/types/reports";
import { useTranslation } from "react-i18next";
import WorkItemForm from "./WorkItemForm";

interface SelectOption {
  value: string;
  label: string;
}

interface WorkItemListProps {
  items: WorkItemFormData[];
  errors: Record<string, WorkItemValidationErrors>;
  customerOptions: SelectOption[];
  productOptions: SelectOption[];
  onItemChange: (
    id: string,
    changes: Partial<Omit<WorkItemFormData, "id">>,
  ) => void;
  onItemBlur: (id: string, field: WorkItemField) => void;
  onItemAdd: () => void;
  onItemDelete: (id: string) => void;
}

export default function WorkItemList({
  items,
  errors,
  customerOptions,
  productOptions,
  onItemChange,
  onItemBlur,
  onItemAdd,
  onItemDelete,
}: WorkItemListProps) {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport" });

  return (
    <div className="space-y-5">
      {items.map((item, index) => (
        <WorkItemForm
          key={item.id}
          index={index}
          item={item}
          errors={errors[item.id]}
          customerOptions={customerOptions}
          productOptions={productOptions}
          canDelete={items.length > 1}
          onChange={onItemChange}
          onBlur={onItemBlur}
          onDelete={onItemDelete}
        />
      ))}

      <Button
        type="button"
        variant="outline"
        size="sm"
        startIcon={<PlusIcon className="size-5" />}
        onClick={onItemAdd}
      >
        {t("addWorkItem")}
      </Button>
    </div>
  );
}
