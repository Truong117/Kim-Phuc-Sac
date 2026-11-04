export type IntegrationTab = "drive" | "social";
export const platforms = ["tiktok", "facebook", "zalo", "metaAds"] as const;
export type Platform = (typeof platforms)[number];
export interface IntegrationEntry {
  id: string;
  kind: IntegrationTab;
  platform: Platform;
  name: string;
  url: string;
  owner: string;
  note: string;
  enabled: boolean;
  sample?: boolean;
}
export const sampleEntries: IntegrationEntry[] = [
  {
    id: "sample-drive-1",
    kind: "drive",
    platform: "facebook",
    name: "Kế hoạch Marketing · mẫu",
    url: "https://drive.google.com/drive/folders/kps-marketing-demo",
    owner: "Nhân sự mẫu 01",
    note: "",
    enabled: true,
    sample: true,
  },
  {
    id: "sample-drive-2",
    kind: "drive",
    platform: "facebook",
    name: "Kế hoạch Kinh doanh · mẫu",
    url: "https://drive.google.com/drive/folders/kps-sales-demo",
    owner: "Nhân sự mẫu 02",
    note: "",
    enabled: false,
    sample: true,
  },
  ...(["tiktok", "facebook", "tiktok", "zalo", "metaAds"] as Platform[]).map(
    (platform, index) => ({
      id: `sample-social-${index}`,
      kind: "social" as const,
      platform,
      name: [
        "KPS Skinlab · mẫu",
        "Kim Phục Sắc · mẫu",
        "Đào tạo KPS · mẫu",
        "KPS Official Account · mẫu",
        "Quảng cáo KPS · mẫu",
      ][index],
      url: [
        "https://www.tiktok.com/@kps-demo",
        "https://www.facebook.com/kps-demo",
        "https://www.tiktok.com/@kps-training-demo",
        "https://oa.zalo.me/kps-demo",
        "https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=123456789",
      ][index],
      owner: `Nhân sự mẫu 0${(index % 2) + 1}`,
      note: "",
      enabled: true,
      sample: true,
    }),
  ),
];
export const blankEntry = (kind: IntegrationTab): IntegrationEntry => ({
  id: "",
  kind,
  platform: "tiktok",
  name: "",
  url: "",
  owner: "",
  note: "",
  enabled: true,
});
export function validUrl(entry: IntegrationEntry): boolean {
  try {
    const url = new URL(entry.url);
    if (url.protocol !== "https:" || url.username || url.password) return false;
    const host = url.hostname.toLowerCase();
    if (entry.kind === "drive")
      return (
        host === "drive.google.com" &&
        /^\/drive\/(?:u\/\d+\/)?folders\/[^/]+/.test(url.pathname)
      );
    const domains: Record<Platform, string[]> = {
      tiktok: ["tiktok.com"],
      facebook: ["facebook.com", "fb.com"],
      zalo: ["zalo.me", "zaloapp.com"],
      metaAds: ["adsmanager.facebook.com", "business.facebook.com"],
    };
    return (
      domains[entry.platform].some(
        (domain) => host === domain || host.endsWith(`.${domain}`),
      ) && url.pathname !== "/"
    );
  } catch {
    return false;
  }
}
export function normalizedUrl(value: string): string {
  try {
    const url = new URL(value);
    const identity = new URLSearchParams();
    for (const key of ["act", "id"]) {
      const id = url.searchParams.get(key);
      if (id) identity.set(key, id);
    }
    return `${url.hostname.replace(/^www\./, "")}${url.pathname.replace(/\/$/, "")}${identity.size ? `?${identity}` : ""}`;
  } catch {
    return value;
  }
}
export function readEntries(key: string): {
  entries: IntegrationEntry[];
  warning: boolean;
} {
  try {
    const raw = localStorage.getItem(key);
    if (!raw)
      return { entries: structuredClone(sampleEntries), warning: false };
    const saved: unknown = JSON.parse(raw);
    if (
      !Array.isArray(saved) ||
      !saved.every((row: unknown) => {
        if (!row || typeof row !== "object") return false;
        const r = row as IntegrationEntry;
        return (
          typeof r.id === "string" &&
          ["drive", "social"].includes(r.kind) &&
          platforms.includes(r.platform) &&
          [r.name, r.url, r.owner, r.note].every(
            (v) => typeof v === "string",
          ) &&
          typeof r.enabled === "boolean" &&
          validUrl(r)
        );
      })
    )
      throw new Error("Invalid stored demo");
    return { entries: saved as IntegrationEntry[], warning: false };
  } catch {
    return { entries: structuredClone(sampleEntries), warning: true };
  }
}
