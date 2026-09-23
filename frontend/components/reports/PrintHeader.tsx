"use client";
import BrandingAssetImage from "@/components/print/BrandingAssetImage";
import PrintWatermark from "@/components/print/PrintWatermark";
import { localizedPrintText, localeDirection, resolvePrintLocale, resolvePrintOptions, printVisible, type PrintFamily } from "@/lib/print-branding";

type Props = {
  profile?: any;
  title: string;
  filters?: { from_date?: string; to_date?: string };
  family?: PrintFamily;
  showApprovals?: boolean;
};

export default function PrintHeader({ profile, title, filters, family="report", showApprovals=true }: Props) {
  const locale = resolvePrintLocale(profile?.print_locale || profile?.default_language);
  const resolvedFamily: PrintFamily = profile?.print_family || family;
  const options = resolvePrintOptions(profile?.print_options, resolvedFamily);
  const companyName = profile?.print_company_name || profile?.company_name || profile?.company_name_fallback || "صلب ERP";
  return (
    <header dir={localeDirection(locale)} className={`print-branding-header relative z-10 border-b-2 ${options.variant === "COMPACT" ? "mb-2 pb-2" : "mb-5 pb-4"}`} style={{borderColor:options.variant === "MODERN" ? profile?.secondary_color || "#123D68" : profile?.primary_color||"#0B2A4A"}}>
      <PrintWatermark profile={profile} family={resolvedFamily} />
      {printVisible(options,"header_image") && options.header_mode !== "TEXT" && <BrandingAssetImage asset="header_image" scopeKey={profile?.company_id} enabled={Boolean(profile?.header_image_url || profile?.has_header_image || profile?.header_image_path)} alt="ترويسة الشركة" className="mb-2 w-full object-contain" style={{maxHeight:`${Math.max(10,Math.min(60,Number(options.header_height_mm)||28))}mm`}} />}
      {options.header_mode !== "FULL_IMAGE" && <>
      <div className="flex items-start justify-between gap-5">
        <div className="flex items-start gap-4">
          {printVisible(options,"logo") && options.header_mode !== "TEXT" && <BrandingAssetImage asset="logo" scopeKey={profile?.company_id} enabled={Boolean(profile?.logo_url || profile?.logo_data_uri || profile?.has_logo || profile?.logo_path)} alt="شعار الشركة" className="h-16 w-16 object-contain" />}
          <div>
            <div className="text-xl font-black text-[#0B2A4A]">
              {printVisible(options,"company_name") ? companyName : null}
            </div>
            {!printVisible(options,"company_details") ? null : <><div className="mt-1 text-sm text-slate-500">
              {[profile?.phone || profile?.print_phone, profile?.email || profile?.print_email, profile?.city || profile?.print_city].filter(Boolean).join(" • ")}
            </div>
            {profile?.address || profile?.print_address ? <div className="text-xs text-slate-500">{profile.address || profile.print_address}</div> : null}</>}
            {localizedPrintText(profile?.print_header_texts, locale) ? <div className="mt-2 whitespace-pre-line text-xs">{localizedPrintText(profile.print_header_texts, locale)}</div> : null}
          </div>
        </div>
        <div className="text-left text-xs text-slate-500">
          {printVisible(options,"commercial_register") && profile?.commercial_register ? <div>السجل: {profile.commercial_register}</div> : null}
          {printVisible(options,"tax_number") && profile?.tax_number ? <div>الرقم الضريبي: {profile.tax_number}</div> : null}
        </div>
      </div></>}
      <div className="mt-4 flex items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-slate-900">{title}</h1>
          {printVisible(options,"branch") && <div className="mt-1 text-xs text-slate-500">الفرع: {profile?.branch_name || "جميع الفروع"}</div>}
        </div>
        {(filters?.from_date || filters?.to_date) && (
          <div className="text-xs font-bold text-slate-600">
            الفترة: {filters?.from_date || "البداية"} — {filters?.to_date || "اليوم"}
          </div>
        )}
      </div>
      {showApprovals && resolvedFamily !== "road" && (printVisible(options,"signature") && (profile?.signature_url || profile?.signature_path || profile?.has_signature) || printVisible(options,"stamp") && (profile?.stamp_url || profile?.stamp_path || profile?.has_stamp)) ? (
        <div className="mt-3 flex items-end justify-end gap-5" aria-label="اعتمادات المستند">
          {printVisible(options,"signature") && <BrandingAssetImage asset="signature" scopeKey={profile?.company_id} enabled={Boolean(profile?.signature_url || profile?.signature_path || profile?.has_signature)} alt="صورة التوقيع المحفوظة؛ ليست توقيعًا إلكترونيًا" className="h-12 max-w-32 object-contain" />}
          {printVisible(options,"stamp") && <BrandingAssetImage asset="stamp" scopeKey={profile?.company_id} enabled={Boolean(profile?.stamp_url || profile?.stamp_path || profile?.has_stamp)} alt="صورة الختم المحفوظة؛ ليست اعتمادًا إلكترونيًا" className="h-14 w-14 object-contain" />}
        </div>
      ) : null}
    </header>
  );
}
