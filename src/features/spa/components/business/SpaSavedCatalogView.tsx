import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { PlusIcon } from "@/icons";
import { ApiServiceError } from "@/services/apiClient";
import { spaMoney } from "../../businessSelectors";
import {
  getSpaCatalog,
  saveSpaCatalogItem,
  type SpaCatalogItem,
  type SpaCatalogKind,
  type SpaCatalogPage,
} from "../../services/spaCatalogService";
import SpaActionDialog, { type SpaActionForm } from "./SpaActionDialog";
import {
  SpaAction,
  SpaEmpty,
  SpaHint,
  SpaSearch,
  SpaTabs,
  spaPanel,
} from "./SpaBusinessUi";

export default function SpaSavedCatalogView() {
  const { t } = useTranslation();
  const b = (key: string) => t(`spa.business.${key}`);
  const [kind, setKind] = useState<SpaCatalogKind>("service");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [refresh, setRefresh] = useState(0);
  const [result, setResult] = useState<SpaCatalogPage>();
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [form, setForm] = useState<SpaActionForm | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    const timer = setTimeout(() => {
      getSpaCatalog(kind, query, page, controller.signal)
        .then((data) => {
          if (!controller.signal.aborted) {
            setResult(data);
            setState("ready");
          }
        })
        .catch(() => {
          if (!controller.signal.aborted) setState("error");
        });
    }, 250);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [kind, query, page, refresh]);
  const reload = () => {
    setState("loading");
    setRefresh((n) => n + 1);
  };
  const openForm = (item?: SpaCatalogItem) => {
    const itemKind = item?.kind ?? kind;
    const service = itemKind === "service";
    const id = item?.id ?? crypto.randomUUID();
    setForm({
      title: b(
        service
          ? item
            ? "editService"
            : "newService"
          : item
            ? "editProduct"
            : "newProduct",
      ),
      description: b("catalogFormHint"),
      saveLabel: b("catalogSave"),
      messages: {
        catalogValidation: b("catalogValidation"),
        catalogConflict: b("catalogConflict"),
        catalogUnavailable: b("catalogUnavailable"),
      },
      initial: {
        name: item?.name ?? "",
        price: String(item?.price ?? 0),
        unit: item?.unit ?? "",
        duration: String(item?.duration_minutes ?? 60),
        group: item?.group ?? "",
        description: item?.description ?? "",
        photo: item?.requires_skin_photo ? "yes" : "no",
      },
      fields: [
        {
          key: "name",
          label: b(service ? "serviceName" : "productName"),
          errors: ["nameRequired", "catalogValidation"],
        },
        ...(service
          ? [
              {
                key: "group",
                label: b("group"),
                required: false,
                errors: ["catalogGroupInvalid" as const],
              },
              {
                key: "duration",
                label: b("duration"),
                type: "number" as const,
                min: 15,
                max: 180,
                step: 15,
                errors: ["timeInvalid" as const],
              },
            ]
          : [
              {
                key: "unit",
                label: b("unit"),
                errors: ["unitInvalid" as const],
              },
            ]),
        {
          key: "price",
          label: b("price"),
          type: "money",
          min: 0,
          max: 1000000000,
          step: 1,
          errors: ["amountInvalid"],
        },
        ...(service
          ? [
              {
                key: "description",
                label: b("description"),
                type: "textarea" as const,
                required: false,
                errors: ["noteTooLong" as const],
              },
              {
                key: "photo",
                label: b("photo"),
                type: "select" as const,
                options: ["yes", "no"].map((value) => ({
                  value,
                  label: b(value),
                })),
              },
            ]
          : []),
      ],
      submit: async (v) => {
        if (!v.name.trim() || v.name.trim().length > 100) return "nameRequired";
        if (!service && (!v.unit.trim() || v.unit.trim().length > 30))
          return "unitInvalid";
        const price = Number(v.price),
          duration = Number(v.duration);
        if (!Number.isSafeInteger(price) || price < 0 || price > 1000000000)
          return "amountInvalid";
        if (
          service &&
          (!Number.isInteger(duration) ||
            duration < 15 ||
            duration > 180 ||
            duration % 15)
        )
          return "timeInvalid";
        if (service && v.description.length > 1000) return "noteTooLong";
        if (service && v.group.length > 100) return "catalogGroupInvalid";
        try {
          await saveSpaCatalogItem(
            {
              ...(item ? { version: item.version } : { id }),
              kind: itemKind,
              name: v.name.trim(),
              price,
              ...(service
                ? {
                    duration_minutes: duration,
                    group: v.group,
                    description: v.description,
                    requires_skin_photo: v.photo === "yes",
                  }
                : { unit: v.unit.trim() }),
            },
            item?.id,
          );
          setQuery("");
          setPage(1);
          reload();
          return undefined;
        } catch (error) {
          if (error instanceof ApiServiceError) {
            if (error.status === 409) return "catalogConflict";
            if (error.status === 422) return "catalogValidation";
          }
          return "catalogUnavailable";
        }
      },
    });
  };
  return (
    <div className="space-y-5">
      <SpaHint>{b("catalogSavedHint")}</SpaHint>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <SpaTabs
          items={[
            { key: "service", label: b("services") },
            { key: "product", label: b("retail") },
          ]}
          value={kind}
          onChange={(value) => {
            if (value === kind) return;
            setKind(value as SpaCatalogKind);
            setQuery("");
            setPage(1);
            setState("loading");
          }}
        />
        <SpaAction disabled={state !== "ready"} onClick={() => openForm()}>
          <span className="inline-flex items-center gap-2">
            <PlusIcon className="size-5 shrink-0" aria-hidden="true" />
            {b(kind === "service" ? "newService" : "newProduct")}
          </span>
        </SpaAction>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <SpaSearch
          value={query}
          onChange={(value) => {
            setQuery(value);
            setPage(1);
            setState("loading");
          }}
        />
        <SpaAction secondary onClick={reload}>
          {b("catalogRetry")}
        </SpaAction>
      </div>
      {state === "loading" ? (
        <p role="status">{b("catalogLoading")}</p>
      ) : state === "error" ? (
        <p role="alert">{b("catalogUnavailable")}</p>
      ) : (
        <>
          <div className="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
            {result?.data.map((item) => (
              <article key={item.id} className={spaPanel}>
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  {item.kind === "service"
                    ? item.group || b("services")
                    : `${b("retail")} · ${item.unit}`}
                </p>
                <h2 className="mt-2 text-lg font-semibold break-words text-gray-900 dark:text-white">
                  {item.name}
                </h2>
                <p className="mt-3 text-xl font-semibold text-kps-primary dark:text-gray-200">
                  {spaMoney(item.price)}
                </p>
                {item.kind === "service" && (
                  <>
                    <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
                      {t("spa.minutes", { count: item.duration_minutes })}
                    </p>
                    <p className="mt-3 text-sm break-words text-gray-600 dark:text-gray-300">
                      {item.description}
                    </p>
                  </>
                )}
                <div className="mt-5">
                  <SpaAction secondary onClick={() => openForm(item)}>
                    {b(item.kind === "service" ? "editService" : "editProduct")}
                  </SpaAction>
                </div>
              </article>
            ))}
          </div>
          {!result?.data.length && <SpaEmpty />}
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {b("catalogTotal")}: {result?.meta.total ?? 0} · {page}/
              {result?.meta.last_page ?? 1}
            </p>
            <div className="flex gap-2">
              <SpaAction
                secondary
                disabled={page <= 1}
                onClick={() => {
                  setPage((n) => n - 1);
                  setState("loading");
                }}
              >
                {b("catalogPrevious")}
              </SpaAction>
              <SpaAction
                secondary
                disabled={page >= (result?.meta.last_page ?? 1)}
                onClick={() => {
                  setPage((n) => n + 1);
                  setState("loading");
                }}
              >
                {b("catalogNext")}
              </SpaAction>
            </div>
          </div>
        </>
      )}
      {form && <SpaActionDialog form={form} onClose={() => setForm(null)} />}
    </div>
  );
}
