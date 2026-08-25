type NumericValue = number | string | null | undefined;

function safeNumber(value: NumericValue): number {
  if (value === null || value === undefined || value === "") return 0;
  const parsed = typeof value === "number" ? value : Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

export function formatNumber(
  value: NumericValue,
  options: { minimumFractionDigits?: number; maximumFractionDigits?: number } = {}
): string {
  const minimumFractionDigits = Math.max(0, options.minimumFractionDigits ?? 0);
  const maximumFractionDigits = Math.max(
    minimumFractionDigits,
    options.maximumFractionDigits ?? 3
  );

  return new Intl.NumberFormat("en-US", {
    useGrouping: true,
    minimumFractionDigits,
    maximumFractionDigits,
  }).format(safeNumber(value));
}

export function formatMoney(value: NumericValue, decimals = 2): string {
  const precision = Math.max(0, Math.min(8, Math.trunc(decimals)));
  return formatNumber(value, {
    minimumFractionDigits: precision,
    maximumFractionDigits: precision,
  });
}

export function formatQuantity(value: NumericValue, decimals = 3): string {
  return formatNumber(value, { maximumFractionDigits: decimals });
}

export function formatPercentage(value: NumericValue, decimals = 2): string {
  return `${formatNumber(value, { maximumFractionDigits: decimals })}%`;
}

export function formatDate(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);

  return new Intl.DateTimeFormat("en-GB", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).format(date);
}
