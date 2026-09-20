"use client";

import BrandingAssetImage from "@/components/print/BrandingAssetImage";
import { localizedPrintText, localeDirection, resolvePrintLocale } from "@/lib/print-branding";

export default function PrintFooter({ profile, kind="report" }: { profile?: any; kind?: "report" | "invoice" }) {
  const locale = resolvePrintLocale(profile?.print_locale || profile?.default_language);
  const localized = localizedPrintText(profile?.print_footer_texts, locale);
  const fallback = kind === "invoice" ? profile?.invoice_footer : profile?.report_footer;
  return <footer dir={localeDirection(locale)} className="print-branding-footer mt-8 border-t pt-3 text-center text-xs text-slate-500">
    <div className="whitespace-pre-line">{localized || fallback || "تم إنشاء المستند من نظام صلب ERP"}</div>
    <BrandingAssetImage asset="footer_image" enabled={Boolean(profile?.footer_image_url || profile?.has_footer_image || profile?.footer_image_path)} alt="تذييل الشركة" className="mx-auto mt-2 w-full object-contain" />
  </footer>;
}
