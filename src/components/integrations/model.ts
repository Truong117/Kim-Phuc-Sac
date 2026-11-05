export type IntegrationTab = "drive" | "social";
export const platforms = ["tiktok", "facebook", "zalo", "metaAds"] as const;
export type Platform = (typeof platforms)[number];
export interface IntegrationEntry {
  id: string;
  kind: IntegrationTab;
  platform: Platform | null;
  name: string;
  url: string;
  owner: string;
  note: string;
  enabled: boolean;
  responsible_user_id: string;
  version: number;
  connection_status?:
    "not_connected" | "connected" | "disconnected" | "reconnect_required";
}
export const blankEntry = (kind: IntegrationTab): IntegrationEntry => ({
  id: "",
  kind,
  platform: kind === "drive" ? null : "tiktok",
  name: "",
  url: "",
  owner: "",
  responsible_user_id: "",
  note: "",
  enabled: true,
  version: 0,
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
      (entry.platform ? domains[entry.platform] : []).some(
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
