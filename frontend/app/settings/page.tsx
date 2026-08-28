"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import api from "../api";
import SystemDialog from "@/components/common/SystemDialog";
import { LoadingState, PageHeader, primaryButtonClassName, secondaryButtonClassName } from "@/components/ui/enterprise";
import { EnterpriseField, EnterpriseFormSection, EnterpriseTabs, StickyActionBar, type WorkspaceTab } from "@/components/design-system/EnterpriseWorkspace";
import { readSession } from "@/lib/session";

type Dialog = { open: boolean; type: "success" | "error" | "warning" | "info"; title: string; message: string };
const closed: Dialog = { open: false, type: "info", title: "", message: "" };
const empty: any = { print_company_name: "", print_phone: "", print_email: "", tax_number: "", commercial_register: "", invoice_footer: "", report_footer: "", primary_color: "#0B2A4A", secondary_color: "#123D68", logo_url: "", signature_url: "", stamp_url: "", legal_name: "", registration_number: "", country_code: "", default_language: "ar", timezone: "UTC", short_address: "", building_no: "", street_name: "", district: "", city: "", state_region: "", postal_code: "", additional_no: "", unit_no: "", address_line1: "", address_line2: "", base_currency_code: "" };
const tabs: WorkspaceTab[] = [
  { id: "legal", label: "البيانات القانونية" },
  { id: "address", label: "العنوان والتواصل" },
  { id: "localization", label: "التوطين والعملة" },
  { id: "branding", label: "الهوية والطباعة" },
  { id: "documents", label: "إعدادات المستندات" },
];

function err(error: any) { const errors = error?.response?.data?.errors; if (errors) { const first = Object.values(errors).flat().find(Boolean); if (first) return String(first); } return String(error?.response?.data?.message || error?.message || "تعذر إكمال العملية."); }

export default function SettingsPage() {
  const session = useMemo(() => readSession(), []);
  const readOnlySupport = session?.user?.is_support_mode === true && session.user.support_access_level !== "WRITE";
  const [settings, setSettings] = useState<any>(empty);
  const [savedSnapshot, setSavedSnapshot] = useState(JSON.stringify(empty));
  const [activeTab, setActiveTab] = useState("legal");
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [dialog, setDialog] = useState<Dialog>(closed);
  const dirty = JSON.stringify(settings) !== savedSnapshot;
  const notify = (type: Dialog["type"], title: string, message: string) => setDialog({ open: true, type, title, message });
  const update = (key: string, value: unknown) => setSettings((current: any) => ({ ...current, [key]: value }));

  const load = async () => {
    setLoading(true);
    try {
      const response = await api.get("/company-settings");
      const data = response.data?.data || {};
      const company = data.company || {};
      const address = data.address_details || {};
      const next = { ...empty, ...data, legal_name: company.legal_name || data.print_company_name || company.company_name || "", registration_number: company.registration_number || data.commercial_register || "", tax_number: company.tax_number || data.tax_number || "", country_code: company.country_code || data.country_code || "", default_language: company.default_language || "ar", timezone: company.timezone || "UTC", short_address: address.short_address || "", building_no: address.building_no || "", street_name: address.street_name || "", district: address.district || "", city: address.city || company.city || data.print_city || "", state_region: address.state_region || "", postal_code: address.postal_code || "", additional_no: address.additional_no || "", unit_no: address.unit_no || "", address_line1: address.address_line1 || company.address || data.print_address || "", address_line2: address.address_line2 || "", base_currency_code: data.base_currency_code || data.currency_code || "" };
      setSettings(next);
      setSavedSnapshot(JSON.stringify(next));
    } catch (error: any) { notify("error", "تعذر تحميل الإعدادات", err(error)); } finally { setLoading(false); }
  };
  useEffect(() => { void load(); }, []);
  useEffect(() => { if (!dirty) return; const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); }; window.addEventListener("beforeunload", warn); return () => window.removeEventListener("beforeunload", warn); }, [dirty]);

  const save = async () => {
    setSaving(true);
    try {
      const payload = { print_company_name: settings.print_company_name || settings.legal_name || null, print_phone: settings.print_phone || null, print_email: settings.print_email || null, tax_number: settings.tax_number || null, commercial_register: settings.registration_number || null, invoice_footer: settings.invoice_footer || null, report_footer: settings.report_footer || null, primary_color: settings.primary_color || "#0B2A4A", secondary_color: settings.secondary_color || "#123D68", legal_name: settings.legal_name || null, registration_number: settings.registration_number || null, country_code: settings.country_code ? String(settings.country_code).toUpperCase() : null, default_language: settings.default_language || "ar", timezone: settings.timezone || "UTC", short_address: settings.short_address || null, building_no: settings.building_no || null, street_name: settings.street_name || null, district: settings.district || null, city: settings.city || null, state_region: settings.state_region || null, postal_code: settings.postal_code || null, additional_no: settings.additional_no || null, unit_no: settings.unit_no || null, address_line1: settings.address_line1 || null, address_line2: settings.address_line2 || null, print_city: settings.city || null, print_address: settings.address_line1 || null };
      const response = await api.post("/company-settings", payload);
      await load();
      notify("success", "تم الحفظ", response.data?.message || "تم حفظ إعدادات الشركة.");
    } catch (error: any) { notify("error", "تعذر الحفظ", err(error)); } finally { setSaving(false); }
  };

  const fileToDataUrl = (file: File) => new Promise<string>((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result || "")); reader.onerror = () => reject(new Error("تعذر قراءة الملف.")); reader.readAsDataURL(file); });
  const upload = async (type: "logo" | "signature" | "stamp", file?: File) => {
    if (!file) return;
    const allowed = ["image/png", "image/jpeg", "image/webp"];
    if (!allowed.includes(file.type)) { notify("warning", "ملف غير مدعوم", "يسمح فقط بملفات PNG أو JPG/JPEG أو WEBP."); return; }
    if (file.size > 5 * 1024 * 1024) { notify("warning", "حجم الملف كبير", "الحد الأقصى 5 MB."); return; }
    try { const dataUrl = await fileToDataUrl(file); const response = await api.post("/company-settings/upload", { type, filename: file.name, mime_type: file.type, file_base64: dataUrl }); await load(); notify("success", "تم رفع الملف", response.data?.message || "تم رفع الملف بنجاح."); } catch (error: any) { notify("error", "تعذر رفع الملف", err(error)); }
  };

  return <section dir="rtl" className="space-y-3 pb-20">
    <PageHeader title="إعدادات الشركة" description="إدارة الهوية القانونية والتوطين والعنوان وهوية المستندات ضمن مساحة عمل موحدة." breadcrumbs={[{ label: "الرئيسية", href: "/" }, { label: "إعدادات الشركة" }]} actions={<><Link href="/settings/print-branding" className={secondaryButtonClassName}>معاينة هوية الطباعة</Link><Link href="/financial-setup" className={secondaryButtonClassName}>الإعداد المالي ←</Link></>} />
    {loading ? <div className="rounded-lg border border-slate-200 bg-white"><LoadingState label="جاري تحميل إعدادات الشركة..." /></div> : <>
      <EnterpriseTabs tabs={tabs} active={activeTab} onChange={setActiveTab} />
      {activeTab === "legal" ? <EnterpriseFormSection title="البيانات القانونية" description="البيانات الرسمية والتجارية التي تظهر في المستندات والتقارير."><div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3"><Field label="الاسم الظاهر / التجاري"><input value={settings.print_company_name || ""} onChange={(e) => update("print_company_name", e.target.value)} className="input" /></Field><Field label="الاسم القانوني"><input value={settings.legal_name || ""} onChange={(e) => update("legal_name", e.target.value)} className="input" /></Field><Field label="السجل / رقم المنشأة"><input value={settings.registration_number || ""} onChange={(e) => update("registration_number", e.target.value)} className="input" /></Field><Field label="الرقم الضريبي"><input value={settings.tax_number || ""} onChange={(e) => update("tax_number", e.target.value)} className="input" /></Field><Field label="الجوال"><input value={settings.print_phone || ""} onChange={(e) => update("print_phone", e.target.value)} className="input" /></Field><Field label="البريد"><input type="email" value={settings.print_email || ""} onChange={(e) => update("print_email", e.target.value)} className="input" /></Field></div></EnterpriseFormSection> : null}
      {activeTab === "address" ? <EnterpriseFormSection title="العنوان والتواصل" description="العنوان الوطني أو الدولي المستخدم في المراسلات والمستندات."><div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3"><Field label="العنوان المختصر"><input value={settings.short_address || ""} onChange={(e) => update("short_address", e.target.value)} className="input" /></Field><Field label="رقم المبنى"><input value={settings.building_no || ""} onChange={(e) => update("building_no", e.target.value)} className="input" /></Field><Field label="الشارع"><input value={settings.street_name || ""} onChange={(e) => update("street_name", e.target.value)} className="input" /></Field><Field label="الحي"><input value={settings.district || ""} onChange={(e) => update("district", e.target.value)} className="input" /></Field><Field label="المدينة"><input value={settings.city || ""} onChange={(e) => update("city", e.target.value)} className="input" /></Field><Field label="الولاية / المنطقة / المحافظة"><input value={settings.state_region || ""} onChange={(e) => update("state_region", e.target.value)} className="input" /></Field><Field label="الرمز البريدي"><input value={settings.postal_code || ""} onChange={(e) => update("postal_code", e.target.value)} className="input" /></Field><Field label="الرقم الإضافي"><input value={settings.additional_no || ""} onChange={(e) => update("additional_no", e.target.value)} className="input" /></Field><Field label="رقم الوحدة"><input value={settings.unit_no || ""} onChange={(e) => update("unit_no", e.target.value)} className="input" /></Field><Field label="سطر العنوان 1"><input value={settings.address_line1 || ""} onChange={(e) => update("address_line1", e.target.value)} className="input" /></Field><Field label="سطر العنوان 2"><input value={settings.address_line2 || ""} onChange={(e) => update("address_line2", e.target.value)} className="input" /></Field></div></EnterpriseFormSection> : null}
      {activeTab === "localization" ? <EnterpriseFormSection title="التوطين والعملة" description="بيانات الدولة واللغة والمنطقة الزمنية. العملة الأساسية ودقتها للعرض فقط هنا."><div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3"><Field label="الدولة (ISO-2)"><input maxLength={2} value={settings.country_code || ""} onChange={(e) => update("country_code", e.target.value.toUpperCase().replace(/[^A-Z]/g, ""))} placeholder="YE / SA / AE" className="input" /></Field><Field label="اللغة الافتراضية"><input value={settings.default_language || "ar"} onChange={(e) => update("default_language", e.target.value)} className="input" /></Field><Field label="المنطقة الزمنية"><input value={settings.timezone || "UTC"} onChange={(e) => update("timezone", e.target.value)} placeholder="Asia/Aden" className="input" /></Field><Field label="العملة الأساسية"><div className="input bg-slate-50 text-slate-600">{settings.base_currency_code || "لم تحدد بعد"}</div></Field>{settings.currency_decimal_places !== undefined && settings.currency_decimal_places !== null ? <Field label="دقة العملة"><div className="input bg-slate-50 text-slate-600">{settings.currency_decimal_places} منازل عشرية</div></Field> : null}</div></EnterpriseFormSection> : null}
      {activeTab === "branding" ? <EnterpriseFormSection title="الهوية والطباعة" description="الشعار والتوقيع والختم والألوان المعتمدة للمستندات."><div className="grid gap-3 md:grid-cols-3"><UploadCard title="شعار الشركة" url={settings.logo_url} disabled={readOnlySupport} onFile={(file) => void upload("logo", file)} /><UploadCard title="التوقيع" url={settings.signature_url} disabled={readOnlySupport} onFile={(file) => void upload("signature", file)} /><UploadCard title="الختم" url={settings.stamp_url} disabled={readOnlySupport} onFile={(file) => void upload("stamp", file)} /></div><div className="mt-4 grid gap-3 md:grid-cols-2"><Field label="اللون الرئيسي"><input type="color" value={settings.primary_color || "#0B2A4A"} onChange={(e) => update("primary_color", e.target.value)} className="h-10 w-full rounded-lg border bg-white p-1" /></Field><Field label="اللون الثانوي"><input type="color" value={settings.secondary_color || "#123D68"} onChange={(e) => update("secondary_color", e.target.value)} className="h-10 w-full rounded-lg border bg-white p-1" /></Field></div></EnterpriseFormSection> : null}
      {activeTab === "documents" ? <EnterpriseFormSection title="إعدادات المستندات" description="التذييلات النصية المستخدمة في الفواتير والتقارير."><div className="grid gap-3 md:grid-cols-2"><Field label="تذييل الفواتير"><textarea value={settings.invoice_footer || ""} onChange={(e) => update("invoice_footer", e.target.value)} className="input min-h-32 resize-y" placeholder="نص أو HTML بسيط" /></Field><Field label="تذييل التقارير"><textarea value={settings.report_footer || ""} onChange={(e) => update("report_footer", e.target.value)} className="input min-h-32 resize-y" placeholder="نص أو HTML بسيط" /></Field></div></EnterpriseFormSection> : null}
      <StickyActionBar dirty={dirty}><button type="button" disabled={saving || readOnlySupport || !dirty} onClick={() => void save()} className={primaryButtonClassName}>{saving ? "جاري الحفظ..." : "حفظ جميع الإعدادات"}</button></StickyActionBar>
    </>}
    <SystemDialog open={dialog.open} type={dialog.type} title={dialog.title} message={dialog.message} onConfirm={() => setDialog(closed)} onClose={() => setDialog(closed)} />
  </section>;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) { return <EnterpriseField label={label}>{children}</EnterpriseField>; }
function UploadCard({ title, url, disabled, onFile }: { title: string; url?: string | null; disabled?: boolean; onFile: (file: File) => void }) { return <div className="rounded-lg border border-slate-200 bg-slate-50 p-3"><div className="text-xs font-semibold text-slate-800">{title}</div><div className="mt-2 flex h-24 items-center justify-center rounded-md border border-slate-100 bg-white">{url ? <img src={url} alt={title} className="max-h-20 max-w-full object-contain" /> : <span className="text-xs text-slate-400">لا يوجد ملف</span>}</div><input disabled={disabled} className="mt-2 w-full text-xs disabled:cursor-not-allowed disabled:opacity-50" type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => e.target.files?.[0] && onFile(e.target.files[0])} /></div>; }
