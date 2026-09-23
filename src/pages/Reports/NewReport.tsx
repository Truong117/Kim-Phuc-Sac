import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import Form from "@/components/form/Form";
import ReportInfo from "@/components/reports/ReportInfo";
import ReportProcessingModal from "@/components/reports/ReportProcessingModal";
import WorkItemList from "@/components/reports/WorkItemList";
import Button from "@/components/ui/button/Button";
import { currentUser } from "@/mocks/currentUser";
import { mockCustomers, mockProducts } from "@/mocks/reports";
import type {
  DailyReportFormData,
  ProcessingStage,
  WorkItemField,
  WorkItemFormData,
  WorkItemTouchedFields,
  WorkItemValidationErrors,
} from "@/types/reports";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

const customerOptions = mockCustomers.map((customer) => ({
  value: customer.id,
  label: customer.name,
}));

const productOptions = mockProducts.map((product) => ({
  value: product.id,
  label: product.name,
}));

function createClientId() {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }

  return `work-item-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function createEmptyWorkItem(): WorkItemFormData {
  return {
    id: createClientId(),
    content: "",
    result: "",
    status: "",
    customerId: undefined,
    productId: undefined,
    note: undefined,
  };
}

function getCurrentDate() {
  const today = new Date();
  const year = today.getFullYear();
  const month = String(today.getMonth() + 1).padStart(2, "0");
  const day = String(today.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function createInitialReport(): DailyReportFormData {
  return {
    employeeId: currentUser.id,
    employeeName: currentUser.name,
    department: currentUser.department,
    reportDate: getCurrentDate(),
    workItems: [createEmptyWorkItem()],
  };
}

export default function NewReport() {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport" });
  const navigate = useNavigate();
  const [report, setReport] =
    useState<DailyReportFormData>(createInitialReport);
  const [touchedFields, setTouchedFields] = useState<
    Record<string, WorkItemTouchedFields>
  >({});
  const [submitAttempted, setSubmitAttempted] = useState(false);
  const [processingStage, setProcessingStage] =
    useState<ProcessingStage | null>(null);
  const isProcessing = processingStage !== null;

  useEffect(() => {
    if (processingStage === "saving") {
      const savingTimer = window.setTimeout(
        () => setProcessingStage("analyzing"),
        800,
      );
      return () => window.clearTimeout(savingTimer);
    }

    if (processingStage === "analyzing") {
      const analyzingTimer = window.setTimeout(
        () => setProcessingStage("completed"),
        1250,
      );
      return () => window.clearTimeout(analyzingTimer);
    }
  }, [processingStage]);

  const handleItemChange = (
    id: string,
    changes: Partial<Omit<WorkItemFormData, "id">>,
  ) => {
    setReport((currentReport) => ({
      ...currentReport,
      workItems: currentReport.workItems.map((item) =>
        item.id === id ? { ...item, ...changes } : item,
      ),
    }));
  };

  const handleItemBlur = (id: string, field: WorkItemField) => {
    setTouchedFields((currentTouchedFields) => ({
      ...currentTouchedFields,
      [id]: {
        ...currentTouchedFields[id],
        [field]: true,
      },
    }));
  };

  const handleAddItem = () => {
    setReport((currentReport) => ({
      ...currentReport,
      workItems: [...currentReport.workItems, createEmptyWorkItem()],
    }));
  };

  const handleDeleteItem = (id: string) => {
    setReport((currentReport) => {
      if (currentReport.workItems.length === 1) return currentReport;

      return {
        ...currentReport,
        workItems: currentReport.workItems.filter((item) => item.id !== id),
      };
    });
    setTouchedFields((currentTouchedFields) => {
      const nextTouchedFields = { ...currentTouchedFields };
      delete nextTouchedFields[id];
      return nextTouchedFields;
    });
  };

  const validateReport = () => {
    const nextErrors: Record<string, WorkItemValidationErrors> = {};

    report.workItems.forEach((item) => {
      const itemErrors: WorkItemValidationErrors = {};

      if (!item.content.trim()) {
        itemErrors.content = t("validation.content");
      }
      if (!item.result.trim()) {
        itemErrors.result = t("validation.result");
      }
      if (!item.status) {
        itemErrors.status = t("validation.status");
      }

      if (Object.keys(itemErrors).length > 0) {
        nextErrors[item.id] = itemErrors;
      }
    });

    return nextErrors;
  };

  const validationErrors = validateReport();
  const visibleErrors = Object.fromEntries(
    Object.entries(validationErrors).map(([id, itemErrors]) => [
      id,
      Object.fromEntries(
        Object.entries(itemErrors).filter(([field]) =>
          Boolean(
            submitAttempted || touchedFields[id]?.[field as WorkItemField],
          ),
        ),
      ) as WorkItemValidationErrors,
    ]),
  );

  const handleSubmit = () => {
    setSubmitAttempted(true);
    if (Object.keys(validationErrors).length > 0) return;

    const submittedReport: DailyReportFormData = {
      ...report,
      workItems: report.workItems.map((item) => ({
        ...item,
        content: item.content.trim(),
        result: item.result.trim(),
        note: item.note?.trim() || undefined,
      })),
    };

    if (import.meta.env.DEV) {
      console.log("Daily report submitted:", submittedReport);
    }

    setReport(submittedReport);
    setProcessingStage("saving");
  };

  const handleCancel = () => {
    setReport(createInitialReport());
    setTouchedFields({});
    setSubmitAttempted(false);
  };

  return (
    <>
      <PageMeta
        title={`${t("title")} | KIM PHỤC SẮC`}
        description={t("metaDescription")}
      />
      <PageBreadCrumb pageTitle={t("title")} />
      <p className="-mt-4 mb-6 text-theme-sm text-gray-500 dark:text-gray-400">
        {t("subtitle")}
      </p>

      <Form onSubmit={handleSubmit} className="space-y-6">
        <ReportInfo
          employeeName={report.employeeName}
          department={report.department}
          reportDate={report.reportDate}
          onReportDateChange={(reportDate) => {
            setReport((currentReport) => ({ ...currentReport, reportDate }));
          }}
        />

        <WorkItemList
          items={report.workItems}
          errors={visibleErrors}
          customerOptions={customerOptions}
          productOptions={productOptions}
          onItemChange={handleItemChange}
          onItemBlur={handleItemBlur}
          onItemAdd={handleAddItem}
          onItemDelete={handleDeleteItem}
        />

        <div className="flex flex-col-reverse gap-3 rounded-2xl border border-gray-200 bg-white p-5 sm:flex-row sm:justify-end dark:border-gray-800 dark:bg-white/3">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="w-full sm:w-auto"
            onClick={handleCancel}
            disabled={isProcessing}
          >
            {t("actions.cancel")}
          </Button>
          <Button
            type="submit"
            size="sm"
            className="w-full sm:w-auto"
            disabled={isProcessing}
          >
            {t("actions.submit")}
          </Button>
        </div>
      </Form>

      <ReportProcessingModal
        isOpen={isProcessing}
        stage={processingStage ?? "saving"}
        onContinue={() => navigate("/reports")}
      />
    </>
  );
}
