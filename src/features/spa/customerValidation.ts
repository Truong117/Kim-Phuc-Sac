import type { SpaCustomer, SpaPreviewError, SpaPreviewSnapshot } from "./types";
export const normalizeSpaPhone = (value: string) => {
  const phone = value.replace(/[\s().-]/g, "");
  return phone.startsWith("+84")
    ? `0${phone.slice(3)}`
    : phone.startsWith("84") && phone.length === 11
      ? `0${phone.slice(2)}`
      : phone;
};
export function validateSpaCustomer(
  snapshot: SpaPreviewSnapshot,
  customer: Omit<SpaCustomer, "id">,
  id: string,
): SpaPreviewError | undefined {
  if (!customer.name.trim() || customer.name.trim().length > 100)
    return "nameRequired";
  const phone = normalizeSpaPhone(customer.phone);
  if (!/^\d{10,15}$/.test(phone)) return "phoneInvalid";
  if (customer.note.length > 1000) return "noteTooLong";
  if (
    snapshot.customers.some(
      (c) => c.id !== id && normalizeSpaPhone(c.phone) === phone,
    )
  )
    return "phoneDuplicate";
}
