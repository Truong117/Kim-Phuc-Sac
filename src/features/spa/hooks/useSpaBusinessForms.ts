import { useTranslation } from "react-i18next";
import { spaPreviewId } from "../csv";
import { spaInvoiceBalance, spaShiftDate } from "../businessSelectors";
import type {
  SpaInvoice,
  SpaProduct,
  SpaServiceDefinition,
  SpaCareTask,
} from "../types";
import type {
  SpaActionForm,
  SpaFormField,
} from "../components/business/SpaActionDialog";
import { useSpaPreview } from "./useSpaPreviewSnapshot";
export default function useSpaBusinessForms() {
  const { t } = useTranslation();
  const { snapshot, execute } = useSpaPreview();
  const b = (key: string) => t(`spa.business.${key}`);
  const field = (
    key: string,
    label: string,
    extra: Partial<SpaFormField> = {},
  ): SpaFormField => ({ key, label: b(label), ...extra });
  const customers = () =>
    field("customerId", "customer", {
      type: "select",
      options: snapshot.customers.map((c) => ({ value: c.id, label: c.name })),
      errors: ["customerRequired"],
    });
  const date = (value: string) => ({ date: value });
  const dateField = () =>
    field("date", "date", { type: "date", errors: ["dateInvalid"] });
  const methodField = () =>
    field("method", "method", {
      type: "select",
      options: ["cash", "transfer"].map((value) => ({
        value,
        label: b(value),
      })),
    });
  const amountField = (key = "amount", label = "amount") =>
    field(key, label, {
      type: "number",
      min: 0,
      step: 1,
      errors: ["amountInvalid"],
    });
  const reasonField = () =>
    field("reason", "reason", { type: "textarea", errors: ["reasonRequired"] });
  return {
    service: (service?: SpaServiceDefinition): SpaActionForm => ({
      title: b(service ? "editService" : "newService"),
      hint: b("serviceHint"),
      initial: {
        name: service?.name ?? "",
        duration: String(service?.durationMinutes ?? 60),
        price: String(service?.price ?? 0),
        group: service?.group ?? "",
        description: service?.description ?? "",
        photo: service?.requiresSkinPhoto ? "yes" : "no",
      },
      fields: [
        field("name", "serviceName", { errors: ["nameRequired"] }),
        field("group", "group", { required: false }),
        field("duration", "duration", {
          type: "number",
          min: 15,
          max: 180,
          step: 15,
          errors: ["timeInvalid", "serviceInUse"],
        }),
        { ...amountField("price", "price"), type: "money" },
        field("description", "description", {
          type: "textarea",
          required: false,
          errors: ["noteTooLong"],
        }),
        field("photo", "photo", {
          type: "select",
          options: ["yes", "no"].map((value) => ({ value, label: b(value) })),
        }),
      ],
      submit: (v) =>
        execute({
          kind: "service-save",
          id: service?.key ?? spaPreviewId("service"),
          name: v.name,
          duration: Number(v.duration),
          price: Number(v.price),
          group: v.group,
          description: v.description,
          requiresSkinPhoto: v.photo === "yes",
        }),
    }),
    product: (onCreated?: () => void): SpaActionForm => ({
      title: b("newProduct"),
      hint: b("productHint"),
      initial: { name: "", unit: "", price: "0" },
      fields: [
        field("name", "productName", { errors: ["nameRequired"] }),
        field("unit", "unit", { errors: ["unitInvalid"] }),
        { ...amountField("price", "price"), type: "money" },
      ],
      submit: (v) => {
        const error = execute({
          kind: "product-create",
          id: spaPreviewId("product"),
          name: v.name,
          unit: v.unit,
          price: Number(v.price),
        });
        if (!error) onCreated?.();
        return error;
      },
    }),
    retail: (product?: SpaProduct, onDate = snapshot.date): SpaActionForm => ({
      title: b("sell"),
      hint: b("sellHint"),
      initial: {
        customerId: "",
        productId: product?.id ?? "",
        quantity: "1",
        ...date(onDate),
      },
      fields: [
        customers(),
        field("productId", "product", {
          type: "select",
          options: snapshot.products
            .filter((p) => p.retail)
            .map((p) => ({
              value: p.id,
              label: `${p.name} · ${p.stock} ${p.unit}`,
            })),
          errors: ["notFound"],
        }),
        field("quantity", "quantity", {
          type: "number",
          min: 1,
          step: 1,
          errors: ["quantityInvalid", "stockInsufficient"],
        }),
        dateField(),
      ],
      submit: (v) =>
        execute({
          kind: "retail-sell",
          id: spaPreviewId("INV"),
          customerId: v.customerId,
          productId: v.productId,
          quantity: Number(v.quantity),
          date: v.date,
        }),
    }),
    package: (): SpaActionForm => ({
      title: b("packageSell"),
      hint: b("packageHint"),
      initial: {
        customerId: "",
        serviceKey: "",
        total: "6",
        price: "0",
        expires: spaShiftDate(snapshot.date, 90),
        ...date(snapshot.date),
      },
      fields: [
        customers(),
        field("serviceKey", "services", {
          type: "select",
          options: snapshot.services.map((s) => ({
            value: s.key,
            label: s.name ?? s.key,
          })),
          errors: ["serviceRequired"],
        }),
        field("total", "totalSessions", {
          type: "number",
          min: 1,
          max: 100,
          step: 1,
          errors: ["quantityInvalid"],
        }),
        amountField("price", "packagePrice"),
        field("expires", "expires", { type: "date", errors: ["dateInvalid"] }),
        dateField(),
      ],
      submit: (v) =>
        execute({
          kind: "package-sell",
          id: spaPreviewId("PKG"),
          customerId: v.customerId,
          serviceKey: v.serviceKey,
          total: Number(v.total),
          price: Number(v.price),
          expires: v.expires,
          date: v.date,
        }),
    }),
    payment: (invoice: SpaInvoice): SpaActionForm => ({
      title: `${b("collect")} · ${invoice.id}`,
      hint: b("paymentHint"),
      initial: {
        amount: String(spaInvoiceBalance(snapshot, invoice.id)),
        method: "cash",
        ...date(invoice.date > snapshot.date ? invoice.date : snapshot.date),
      },
      fields: [amountField(), methodField(), dateField()],
      submit: (v) =>
        execute({
          kind: "payment",
          id: spaPreviewId("PAY"),
          invoiceId: invoice.id,
          amount: Number(v.amount),
          method: v.method as "cash" | "transfer",
          date: v.date,
        }),
    }),
    stock: (product: SpaProduct, direction: "in" | "out"): SpaActionForm => ({
      title: `${b(direction === "in" ? "stockIn" : "stockOut")} · ${product.name}`,
      hint: b("stockHint"),
      initial: { quantity: "1", reason: "", ...date(snapshot.date) },
      fields: [
        field("quantity", "quantity", {
          type: "number",
          min: 1,
          step: 1,
          errors: ["quantityInvalid", "stockInsufficient"],
        }),
        reasonField(),
        dateField(),
      ],
      submit: (v) =>
        execute({
          kind: "stock",
          id: spaPreviewId("MOV"),
          productId: product.id,
          direction,
          quantity: Number(v.quantity),
          reason: v.reason,
          date: v.date,
        }),
    }),
    expense: (onDate: string): SpaActionForm => ({
      title: b("expenseNew"),
      hint: b("financeHint"),
      initial: { amount: "", reason: "", method: "cash", ...date(onDate) },
      fields: [amountField(), reasonField(), methodField(), dateField()],
      submit: (v) =>
        execute({
          kind: "expense",
          id: spaPreviewId("EXP"),
          amount: Number(v.amount),
          reason: v.reason,
          method: v.method as "cash" | "transfer",
          date: v.date,
        }),
    }),
    care: (): SpaActionForm => ({
      title: b("careNew"),
      hint: b("careHint"),
      initial: {
        customerId: "",
        title: "",
        due: snapshot.date,
        channel: "phone",
        staffId: "",
      },
      fields: [
        customers(),
        field("title", "careTitle", { errors: ["nameRequired"] }),
        field("due", "due", { type: "date", errors: ["dateInvalid"] }),
        field("channel", "channel", {
          type: "select",
          options: ["phone", "zalo", "email"].map((value) => ({
            value,
            label: b(value),
          })),
        }),
        field("staffId", "assignee", {
          type: "select",
          options: snapshot.roster.map((s) => ({ value: s.id, label: s.name })),
          errors: ["invalidTransition"],
        }),
      ],
      submit: (v) =>
        execute({
          kind: "care-create",
          id: spaPreviewId("CARE"),
          customerId: v.customerId,
          title: v.title,
          due: v.due,
          channel: v.channel as "phone" | "zalo" | "email",
          staffId: v.staffId,
        }),
    }),
    careResult: (task: SpaCareTask): SpaActionForm => ({
      title: b("careResult"),
      initial: { result: task.result },
      fields: [
        field("result", "result", {
          type: "textarea",
          errors: ["resultRequired"],
        }),
      ],
      submit: (v) =>
        execute({ kind: "care-result", id: task.id, result: v.result }),
    }),
  };
}
