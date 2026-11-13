import type {
  CareReportEntry,
  CareCustomerSelection,
  CareWorkItem,
  TaskProgressInput,
  ManagedTask,
  TaskOutput,
} from "@/types/tasks";

export const blankCareEntry = (): CareReportEntry => ({
  client_key: crypto.randomUUID(),
  activities: [{ service: "", result: "" }],
  evidence_links: [],
  attachment_ids: [],
});

export const careEntryName = (entry: CareCustomerSelection) =>
  entry.new_customer?.name.trim() || entry.name?.trim() || "";

export function validCareContact(value: string): boolean {
  if (!value.trim()) return true;
  if (/^\+?[\d\s().-]+$/.test(value.trim())) {
    let phone = value.replace(/[^\d+]/g, "");
    if (phone.startsWith("0")) phone = "+84" + phone.slice(1);
    else if (phone.startsWith("84")) phone = "+" + phone;
    return /^\+[1-9]\d{7,14}$/.test(phone);
  }
  try {
    const url = new URL(value.trim());
    return (
      ["http:", "https:"].includes(url.protocol) &&
      !url.username &&
      !url.password &&
      !url.port &&
      url.hostname.includes(".") &&
      !/^\d+(\.\d+){3}$/.test(url.hostname) &&
      url.pathname !== "/"
    );
  } catch {
    return false;
  }
}

export function careContactIdentity(value: string): string {
  value = value.trim();
  if (!value) return "";
  if (/^\+?[\d\s().-]+$/.test(value)) {
    let n = value.replace(/[^\d+]/g, "");
    if (n.startsWith("0")) n = "+84" + n.slice(1);
    else if (n.startsWith("84")) n = "+" + n;
    return n;
  }
  try {
    const u = new URL(value);
    let host = u.hostname.toLowerCase().replace(/^www\./, "");
    if (["m.facebook.com", "mbasic.facebook.com", "fb.com"].includes(host))
      host = "facebook.com";
    const path = u.pathname.replace(/\/$/, "");
    if (host === "facebook.com" && path === "/profile.php")
      return `${host}${path}?id=${u.searchParams.get("id")}`;
    const query = [...u.searchParams.entries()]
      .filter(([k]) => !/^(utm_|fbclid$|mibextid$|ref$|refsrc$)/i.test(k))
      .sort(([a], [b]) => a.localeCompare(b));
    return (
      host +
      path +
      (query.length ? "?" + new URLSearchParams(query).toString() : "")
    );
  } catch {
    return value;
  }
}

export const careEntryIdentity = (entry: CareCustomerSelection) =>
  entry.customer_id
    ? `KH-${entry.customer_id}`
    : entry.new_customer
      ? `draft-${entry.new_customer.request_key}`
      : "";

export const careEntryOutputs = (
  entries: CareCustomerSelection[],
  task: ManagedTask,
): TaskOutput[] =>
  entries
    .filter((e) => careEntryIdentity(e) && careEntryName(e))
    .map((entry) => {
      const known = task.progress?.outputs.find(
        (o) =>
          o.unit === "khách hàng" &&
          (entry.legacy_output_id
            ? o.id === entry.legacy_output_id
            : entry.customer_id && o.care_customer_id === entry.customer_id),
      );
      return {
        ...(known?.id ? { id: known.id } : {}),
        name: careEntryName(entry),
        reference: known?.reference || careEntryIdentity(entry),
        completed: true,
        ...(entry.customer_id ? { care_customer_id: entry.customer_id } : {}),
      };
    });

export const careReportText = (entries: CareReportEntry[]) =>
  entries
    .filter((e) => careEntryName(e))
    .map(
      (entry) =>
        `${careEntryName(entry)} — ${entry.activities.map((a) => `${a.service.trim()}: ${a.result.trim()}`).join("; ")}`,
    )
    .join("\n");

export const careEntryPayload = (entries: CareReportEntry[]) =>
  entries.map((entry) => ({
    client_key: entry.client_key,
    ...(entry.customer_id ? { customer_id: entry.customer_id } : {}),
    ...(entry.name ? { name: entry.name } : {}),
    ...(entry.new_customer ? { new_customer: entry.new_customer } : {}),
    ...(entry.contact_update?.channel.trim() ||
    entry.contact_update?.value.trim()
      ? { contact_update: entry.contact_update }
      : {}),
    ...(entry.legacy_output_id
      ? { legacy_output_id: entry.legacy_output_id }
      : {}),
    activities: entry.activities,
    evidence_links: entry.evidence_links.filter(Boolean),
    attachment_ids: entry.attachment_ids,
  }));

export const blankCareWork = (): CareWorkItem => ({
  client_key: crypto.randomUUID(),
  services: [],
  customers: [],
  result: "",
  evidence_links: [],
  attachment_ids: [],
});
export const careServiceIdentity = (name: string) =>
  name.normalize("NFKC").trim().replace(/\s+/g, " ").toLocaleLowerCase("vi");
export const careSelections = (works: CareWorkItem[]) => {
  const customers = new Map<string, CareCustomerSelection>();
  for (const entry of works.flatMap((w) => w.customers)) {
    const key = careEntryIdentity(entry);
    if (key && (!customers.has(key) || entry.legacy_output_id))
      customers.set(key, entry);
  }
  return [...customers.values()];
};
export const careWorkOutputs = (works: CareWorkItem[], task: ManagedTask) =>
  careEntryOutputs(careSelections(works), task);
export const careWorkText = (works: CareWorkItem[]) =>
  works
    .map(
      (w, i) =>
        `Công việc ${i + 1} — ${w.services.map((s) => s.name).join("; ")}\nKhách hàng: ${w.customers.map(careEntryName).join("; ")}\nKết quả: ${w.result.trim()}${w.customers
          .filter((c) => c.note?.trim())
          .map((c) => `\n${careEntryName(c)}: ${c.note!.trim()}`)
          .join("")}`,
    )
    .join("\n\n");

export const careWorksFromLegacy = (
  entries: CareReportEntry[],
): CareWorkItem[] =>
  entries.map((entry) => ({
    client_key: entry.client_key,
    services: [
      ...new Map(
        entry.activities
          .filter((a) => a.service.trim())
          .map((a) => [careServiceIdentity(a.service), { name: a.service }]),
      ).values(),
    ],
    customers: careEntryIdentity(entry)
      ? [
          {
            client_key: entry.client_key,
            ...(entry.customer_id ? { customer_id: entry.customer_id } : {}),
            ...(entry.name ? { name: entry.name } : {}),
            ...(entry.new_customer ? { new_customer: entry.new_customer } : {}),
            ...(entry.contact_update
              ? { contact_update: entry.contact_update }
              : {}),
            ...(entry.legacy_output_id
              ? { legacy_output_id: entry.legacy_output_id }
              : {}),
          },
        ]
      : [],
    result:
      entry.activities.length === 1
        ? entry.activities[0].result
        : entry.activities.map((a) => `${a.service}: ${a.result}`).join("\n"),
    evidence_links: [...entry.evidence_links],
    attachment_ids: [...entry.attachment_ids],
    attachment_names: entry.attachment_names,
  }));

export const careWorkPayload = (works: CareWorkItem[]) =>
  works.map((w) => ({
    client_key: w.client_key,
    services: w.services.map((s) => ({
      ...((s.id ?? 0) > 0 ? { id: s.id } : {}),
      name: s.name.trim(),
    })),
    customers: w.customers.map((c) => ({
      client_key: c.client_key,
      ...(c.customer_id ? { customer_id: c.customer_id } : {}),
      ...(c.name ? { name: c.name } : {}),
      ...(c.new_customer ? { new_customer: c.new_customer } : {}),
      ...(c.contact_update?.channel.trim() || c.contact_update?.value.trim()
        ? { contact_update: c.contact_update }
        : {}),
      ...(c.legacy_output_id ? { legacy_output_id: c.legacy_output_id } : {}),
      note: c.note?.trim() ?? "",
    })),
    result: w.result.trim(),
    evidence_links: w.evidence_links.map((s) => s.trim()).filter(Boolean),
    attachment_ids: w.attachment_ids,
  }));

export const careEvidenceEntries = (
  input: TaskProgressInput,
): CareReportEntry[] =>
  input.care_work_items
    ? input.care_work_items.map((w, i) => ({
        client_key: w.client_key,
        name: `Công việc ${i + 1} — ${w.services.map((s) => s.name).join("; ")}`,
        activities: [],
        evidence_links: w.evidence_links,
        attachment_ids: w.attachment_ids,
        attachment_names: w.attachment_names,
      }))
    : (input.care_entries ?? []);

export function careWorkError(
  works: CareWorkItem[],
): "required" | "contact" | "duplicate" | null {
  if (!works.length || works.length > 20 || careSelections(works).length > 50)
    return "required";
  if (new Set(works.map((w) => w.client_key)).size !== works.length)
    return "duplicate";
  for (const w of works) {
    if (
      !w.services.length ||
      w.services.length > 10 ||
      !w.customers.length ||
      w.customers.length > 20 ||
      !w.result.trim() ||
      w.result.length > 1000 ||
      w.services.some((s) => !s.name.trim() || s.name.length > 200) ||
      w.attachment_ids.length > 3 ||
      w.evidence_links.filter(Boolean).length > 3
    )
      return "required";
    if (
      new Set(w.services.map((s) => careServiceIdentity(s.name))).size !==
        w.services.length ||
      new Set(w.customers.map(careEntryIdentity)).size !== w.customers.length
    )
      return "duplicate";
    for (const c of w.customers) {
      if (
        !careEntryName(c) ||
        careEntryName(c).length > 200 ||
        !careEntryIdentity(c) ||
        (c.note?.length ?? 0) > 300 ||
        (c.new_customer && !c.new_customer.channel.trim()) ||
        (c.contact_update &&
          (c.contact_update.channel.trim() || c.contact_update.value.trim()) &&
          (!c.contact_update.channel.trim() || !c.contact_update.value.trim()))
      )
        return "required";
      if (
        !validCareContact(c.new_customer?.contact ?? "") ||
        !validCareContact(c.contact_update?.value ?? "")
      )
        return "contact";
    }
    for (const link of w.evidence_links.filter(Boolean)) {
      try {
        const u = new URL(link);
        if (
          !["http:", "https:"].includes(u.protocol) ||
          u.username ||
          u.password ||
          link.length > 2048
        )
          return "required";
      } catch {
        return "required";
      }
    }
  }
  return null;
}
