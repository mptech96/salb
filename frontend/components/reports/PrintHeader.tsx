"use client";
import BrandingAssetImage from "@/components/print/BrandingAssetImage";
import { localizedPrintText, localeDirection, resolvePrintLocale } from "@/lib/print-branding";

type Props = {
  profile?: any;
  title: string;
  filters?: { from_date?: string; to_date?: string };
};

export default function PrintHeader({ profile, title, filters }: Props) {
  const locale = resolvePrintLocale(profile?.print_locale || profile?.default_language);
  const options = profile?.print_options || {};
  const companyName = profile?.print_company_name || profile?.company_name || profile?.company_name_fallback || "صلب ERP";
  return (
    <header dir={localeDirection(locale)} className="print-branding-header mb-5 border-b-2 pb-4" style={{borderColor:profile?.primary_color||"#0B2A4A"}}>
      <BrandingAssetImage asset="header_image" enabled={Boolean(profile?.header_image_url || profile?.has_header_image || profile?.header_image_path)} alt="ترويسة الشركة" className="mb-2 w-full object-contain" />
      <div className="flex items-start justify-between gap-5">
        <div className="flex items-start gap-4">
          <BrandingAssetImage asset="logo" enabled={Boolean(profile?.logo_url || profile?.logo_data_uri || profile?.has_logo || profile?.logo_path)} alt="شعار الشركة" className="h-16 w-16 object-contain" />
          <div>
            <div className="text-xl font-black text-[#0B2A4A]">
              {options.show_company_name === false ? null : companyName}
            </div>
            {options.show_company_details === false ? null : <><div className="mt-1 text-sm text-slate-500">
              {[profile?.phone, profile?.email, profile?.city].filter(Boolean).join(" • ")}
            </div>
            {profile?.address ? <div className="text-xs text-slate-500">{profile.address}</div> : null}</>}
            {localizedPrintText(profile?.print_header_texts, locale) ? <div className="mt-2 whitespace-pre-line text-xs">{localizedPrintText(profile.print_header_texts, locale)}</div> : null}
          </div>
        </div>
        <div className="text-left text-xs text-slate-500">
          {profile?.commercial_register ? <div>السجل: {profile.commercial_register}</div> : null}
          {profile?.tax_number ? <div>الرقم الضريبي: {profile.tax_number}</div> : null}
        </div>
      </div>
      <div className="mt-4 flex items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black text-slate-900">{title}</h1>
          <div className="mt-1 text-xs text-slate-500">الفرع: {profile?.branch_name || "جميع الفروع"}</div>
        </div>
        {(filters?.from_date || filters?.to_date) && (
          <div className="text-xs font-bold text-slate-600">
            الفترة: {filters?.from_date || "البداية"} — {filters?.to_date || "اليوم"}
          </div>
        )}
      </div>
      {(profile?.signature_url || profile?.stamp_url) ? (
        <div className="mt-3 flex items-end justify-end gap-5" aria-label="اعتمادات المستند">
          <BrandingAssetImage asset="signature" enabled={Boolean(profile?.signature_url || profile?.signature_path)} alt="التوقيع المعتمد" className="h-12 max-w-32 object-contain" />
          <BrandingAssetImage asset="stamp" enabled={Boolean(profile?.stamp_url || profile?.stamp_path)} alt="ختم الشركة" className="h-14 w-14 object-contain" />
        </div>
      ) : null}
    </header>
  );
}
