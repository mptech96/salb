"use client";

import BrandingAssetImage from "@/components/print/BrandingAssetImage";
import PrintApprovalBoxes from "@/components/print/PrintApprovalBoxes";
import { localizedPrintText, localeDirection, resolvePrintLocale, resolvePrintOptions, printVisible, type PrintFamily } from "@/lib/print-branding";

export default function PrintFooter({ profile, kind="report", family }: { profile?: any; kind?: "report" | "invoice"; family?: PrintFamily }) {
  const locale = resolvePrintLocale(profile?.print_locale || profile?.default_language);
  const options = resolvePrintOptions(profile?.print_options, profile?.print_family || family || (kind === "invoice" ? "invoice" : "report"));
  const localized = localizedPrintText(profile?.print_footer_texts, locale);
  const fallback = kind === "invoice" ? profile?.invoice_footer : profile?.report_footer;
  const compactFooter = family === "road" || options.variant === "COMPACT";
  return <>{family === "road" ? <PrintApprovalBoxes profile={profile} family="road" /> : null}<footer dir={localeDirection(locale)} className={`print-branding-footer ${family === "road" ? "print-road-footer" : ""} relative z-10 border-t pt-3 text-center text-xs text-slate-500 ${compactFooter ? "mt-3" : "mt-8"}`}>
    {printVisible(options,"footer_notes") && options.footer_mode !== "IMAGE" && <div className="whitespace-pre-line">{localized || fallback || "تم إنشاء المستند من نظام صلب ERP"}</div>}
    {printVisible(options,"footer_image") && options.footer_mode !== "TEXT" && <BrandingAssetImage asset="footer_image" scopeKey={profile?.company_id} enabled={Boolean(profile?.footer_image_url || profile?.has_footer_image || profile?.footer_image_path)} alt="تذييل الشركة" className="mx-auto mt-2 w-full object-contain" style={{maxHeight:`${Math.max(10,Math.min(50,Number(options.footer_height_mm)||20))}mm`}} />}
  </footer></>;
}
