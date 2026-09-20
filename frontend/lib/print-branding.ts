export type PrintLocale = "ar" | "en" | "ur" | "ja";
export type PrintAsset = "logo" | "header_image" | "footer_image" | "signature" | "stamp";
export const locales: { code: PrintLocale; label: string; dir: "rtl" | "ltr" }[] = [
  { code: "ar", label: "العربية", dir: "rtl" }, { code: "en", label: "English", dir: "ltr" },
  { code: "ur", label: "اردو", dir: "rtl" }, { code: "ja", label: "日本語", dir: "ltr" },
];
export const printText = {
  ar: { title:"معاينة مستند", print:"طباعة", date:"التاريخ", reference:"المرجع", item:"الصنف", qty:"الكمية", price:"السعر", total:"الإجمالي" },
  en: { title:"Document Preview", print:"Print", date:"Date", reference:"Reference", item:"Item", qty:"Quantity", price:"Price", total:"Total" },
  ur: { title:"دستاویز کا پیش منظر", print:"پرنٹ", date:"تاریخ", reference:"حوالہ", item:"آئٹم", qty:"مقدار", price:"قیمت", total:"کل" },
  ja: { title:"文書プレビュー", print:"印刷", date:"日付", reference:"参照番号", item:"品目", qty:"数量", price:"単価", total:"合計" },
};
export function localeDirection(locale: PrintLocale) { return locales.find(x=>x.code===locale)?.dir || "ltr"; }

export function resolvePrintLocale(value?: string): PrintLocale {
  return locales.some((item) => item.code === value) ? value as PrintLocale : "ar";
}

export function localizedPrintText(value: unknown, locale: PrintLocale): string {
  if (!value || typeof value !== "object") return "";
  const texts = value as Partial<Record<PrintLocale, string>>;
  return String(texts[locale] || texts.ar || texts.en || "");
}

const pendingPrintAssets = new Set<Promise<unknown>>();
export function registerPrintAsset<T>(request: Promise<T>): Promise<T> {
  pendingPrintAssets.add(request);
  void request.finally(() => pendingPrintAssets.delete(request));
  return request;
}

export async function printWhenReady(): Promise<void> {
  if (typeof document === "undefined" || typeof window === "undefined") return;
  await Promise.allSettled(Array.from(pendingPrintAssets));
  if ("fonts" in document) await document.fonts.ready;
  const images = Array.from(document.querySelectorAll<HTMLImageElement>(".sulb-print-area img"));
  await Promise.all(images.map(async (img) => {
    if (!img.complete) await new Promise<void>((resolve) => {
      img.addEventListener("load", () => resolve(), { once: true });
      img.addEventListener("error", () => resolve(), { once: true });
    });
    if (img.complete && img.naturalWidth > 0 && typeof img.decode === "function") {
      try { await img.decode(); } catch { /* A missing optional brand image must not block printing. */ }
    }
  }));
  window.print();
}
