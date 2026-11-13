import { apiDownload, apiRequest } from "./apiClient";
import type { CareCustomer } from "@/types/tasks";

export type CareCustomerPage = {
  data: CareCustomer[];
  meta: { current_page: number; last_page: number };
};
export const getCareCustomers = (
  taskId: number,
  search: string,
  page: number,
  signal?: AbortSignal,
) =>
  apiRequest<CareCustomerPage>(
    `/api/tasks/${taskId}/care-customers?${new URLSearchParams({ search, page: String(page) })}`,
    { signal, cache: "no-store" },
  );

export async function uploadCareEvidence(taskId: number, file: File) {
  const body = new FormData();
  body.append("file", file);
  body.append("request_key", crypto.randomUUID());
  return apiRequest<{ data: { id: number; name: string } }>(
    `/api/tasks/${taskId}/care-attachments`,
    { method: "POST", body },
  );
}

export async function downloadCareEvidence(
  taskId: number,
  id: number,
  name: string,
) {
  const blob = await apiDownload(`/api/tasks/${taskId}/care-attachments/${id}`);
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
