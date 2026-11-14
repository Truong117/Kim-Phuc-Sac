/** Keep integer VND as digits; separators are presentation only. */
export const formatSpaMoneyInput = (value: string) =>
  value.replace(/\B(?=(\d{3})+(?!\d))/g, ".");

export function editSpaMoneyInput(
  text: string,
  caret: number,
  previous: string,
  inputType = "",
): { value: string; caret: number } | null {
  // Reject signs, decimals and exponent notation rather than changing their value.
  if (!/^[\d.]*$/.test(text)) return null;
  let digits = text.replace(/\./g, "");
  let before = text.slice(0, caret).replace(/\./g, "").length;
  if (
    digits === previous &&
    text !== formatSpaMoneyInput(previous) &&
    ["deleteContentBackward", "deleteContentForward"].includes(inputType)
  ) {
    // Deleting a separator should delete the adjacent digit, not trap the caret.
    const index = inputType === "deleteContentBackward" ? before - 1 : before;
    digits = previous.slice(0, index) + previous.slice(index + 1);
    if (inputType === "deleteContentBackward") before--;
  }
  const value = digits.replace(/^0+(?=\d)/, "");
  before = Math.max(0, before - (digits.length - value.length));
  const formatted = formatSpaMoneyInput(value);
  let position = 0;
  for (
    let count = 0;
    position < formatted.length && count < before;
    position++
  ) {
    if (formatted[position] !== ".") count++;
  }
  return { value, caret: position };
}
