export const initialExpandedEmployeeKey = (
  employeeKeys: string[],
): string | null => (employeeKeys.length === 1 ? employeeKeys[0] : null);

export const toggleExpandedEmployeeKey = (
  currentKey: string | null,
  toggledKey: string,
): string | null => (currentKey === toggledKey ? null : toggledKey);

export const reconcileExpandedEmployeeKey = (
  currentKey: string | null,
  availableKeys: string[],
  autoExpandSingle = false,
): string | null => {
  if (currentKey !== null && availableKeys.includes(currentKey)) {
    return currentKey;
  }

  return autoExpandSingle ? initialExpandedEmployeeKey(availableKeys) : null;
};
