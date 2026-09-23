export type PrintLocale = "ar" | "en" | "ur" | "ja";
export type PrintAsset = "logo" | "header_image" | "footer_image" | "signature" | "stamp" | "watermark";
export const printFamilies = ["invoice", "voucher", "journal", "report", "statement", "official", "road", "commercial", "inventory"] as const;
export type PrintFamily = typeof printFamilies[number];
export const printVariants = ["CLASSIC", "MODERN", "FULL_HEADER", "COMPACT", "COMPANY", "ROAD_BOXES"] as const;
export type PrintVariant = typeof printVariants[number];
export const printElements = ["logo", "header_image", "footer_image", "company_name", "company_details", "commercial_register", "tax_number", "signature", "stamp", "watermark", "document_number", "document_date", "page_number", "footer_notes", "branch"] as const;
export type PrintElement = typeof printElements[number];
export type PrintOptions = {
  paper?: string; orientation?: string; margin_mm?: number; logo_width_mm?: number; header_height_mm?: number; footer_height_mm?: number;
  show_company_name?: boolean; show_company_details?: boolean;
  visibility?: Partial<Record<PrintElement, boolean>>;
  company_fields?: Record<string, boolean>;
  header_mode?: "FULL_IMAGE" | "LOGO_DETAILS" | "TEXT" | "MIXED";
  footer_mode?: "IMAGE" | "TEXT" | "CONTACT_PAGE" | "MIXED";
  watermark?: { enabled?: boolean; mode?: "TEXT" | "IMAGE"; text?: string; opacity?: number; size?: number; angle?: number; position?: "CENTER" | "TOP" | "BOTTOM"; pages?: "ALL" | "FIRST"; color?: string };
  templates?: Partial<Record<PrintFamily, { selected?: PrintVariant; visibility?: Partial<Record<PrintElement, boolean>>; variants?: Partial<Record<PrintVariant, { visibility?: Partial<Record<PrintElement, boolean>>; watermark?: { enabled?: boolean } }>> }>>;
};

/** Company defaults -> document-family settings -> selected variant override. Old flat flags remain valid. */
export function resolvePrintOptions(raw: unknown, family: PrintFamily = "report"): PrintOptions & { variant: PrintVariant } {
  const options: PrintOptions = raw && typeof raw === "object" ? raw as PrintOptions : {};
  const familyOptions = options.templates?.[family];
  const selected = familyOptions?.selected && printVariants.includes(familyOptions.selected) ? familyOptions.selected : family === "road" ? "ROAD_BOXES" : "CLASSIC";
  const variantOptions = familyOptions?.variants?.[selected];
  return {
    ...options,
    header_mode: options.header_mode || (selected === "FULL_HEADER" ? "FULL_IMAGE" : undefined),
    visibility: { ...options.visibility, ...familyOptions?.visibility, ...variantOptions?.visibility },
    watermark: { ...options.watermark, ...variantOptions?.watermark },
    variant: selected,
  };
}

export function printVisible(options: PrintOptions, element: PrintElement): boolean {
  if ((element === "signature" || element === "stamp") && options.visibility?.[element] !== true) return false;
  if (options.visibility?.[element] === false) return false;
  if (element === "company_name" && options.show_company_name === false) return false;
  if (element === "company_details" && options.show_company_details === false) return false;
  return true;
}
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
