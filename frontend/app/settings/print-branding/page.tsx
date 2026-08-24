"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../../api";

const DEFAULT_INVOICE_FOOTER =
  "شكراً لتعاملكم معنا • هذه الفاتورة صادرة إلكترونياً من نظام صلب ERP. يرجى الاحتفاظ بها للرجوع إليها.";
const DEFAULT_REPORT_FOOTER =
  "تم إعداد هذا التقرير بواسطة نظام صلب ERP • للاستخدام الإداري والتشغيلي.";

const initial = {
  print_company_name: "",
  print_phone: "",
  print_email: "",
  print_city: "",
  print_address: "",
  tax_number: "",
  commercial_register: "",
  currency_name: "ريال",
  currency_code: "SAR",
  logo_path: "",
  signature_path: "",
  stamp_path: "",
  invoice_footer: "",
  report_footer: "",
  primary_color: "#0B2A4A",
  secondary_color: "#123D68",
};

type AssetKind = "logo" | "signature" | "stamp";

function fileUrl(path?: string) {
  if (!path) return "";
  if (/^https?:\/\//i.test(path) || path.startsWith("data:")) return path;
  const apiBase = process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8000/api";
  const root = apiBase.replace(/\/api\/?$/, "");
  return `${root}/storage/${String(path).replace(/^\/?storage\//, "")}`;
}

function errorText(e: any) {
  const data = e?.response?.data;
  const errors = data?.errors;
  if (errors && typeof errors === "object") {
    const msgs = Object.values(errors).flat().filter(Boolean);
    if (msgs.length) return msgs.join(" — ");
  }
  return data?.message || e?.message || "تعذر تنفيذ العملية.";
}

function responsePath(data: any, kind: AssetKind) {
  const d = data?.data ?? data;
  return (
    d?.path ||
    d?.file_path ||
    d?.url ||
    d?.[`${kind}_path`] ||
    d?.settings?.[`${kind}_path`] ||
    ""
  );
}

export default function PrintBrandingPage() {
  const [form, setForm] = useState<any>(initial);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState<AssetKind | null>(null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [preview, setPreview] = useState<Record<AssetKind, string>>({
    logo: "",
    signature: "",
    stamp: "",
  });

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const r = await api.get("/company-settings");
      const s = r.data?.data || {};
      setForm({ ...initial, ...s });
    } catch (e: any) {
      setError(errorText(e));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const imageSrc = (kind: AssetKind) =>
    preview[kind] || fileUrl(form[`${kind}_path`]);

  const uploadAsset = async (kind: AssetKind, file?: File | null) => {
    if (!file) return;
    setError("");
    setMessage("");

    if (!file.type.startsWith("image/")) {
      setError("الشعار والتوقيع والختم يجب أن تكون ملفات صور.");
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      setError("حجم الصورة أكبر من 5 MB. استخدم نسخة أصغر.");
      return;
    }

    const localPreview = URL.createObjectURL(file);
    setPreview((p) => ({ ...p, [kind]: localPreview }));
    setUploading(kind);

    try {
      const fd = new FormData();
      fd.append("file", file, file.name);
      fd.append("type", kind);

      // لا نضع Content-Type هنا. المتصفح يضيف multipart boundary تلقائياً.
      const r = await api.post("/company-settings/upload", fd);
      const path = responsePath(r.data, kind);

      if (path) {
        setForm((f: any) => ({ ...f, [`${kind}_path`]: path }));
      }

      // بعض نسخ الباك تحفظ المسار داخل settings مباشرة ولا تعيده في upload response.
      // لذلك نعيد القراءة لتأكيد النتيجة.
      try {
        const fresh = await api.get("/company-settings");
        const s = fresh.data?.data || {};
        setForm((f: any) => ({ ...f, ...s, [`${kind}_path`]: s?.[`${kind}_path`] || path || f?.[`${kind}_path`] }));
      } catch {}

      setMessage(
        kind === "logo"
          ? "تم رفع شعار الشركة بنجاح."
          : kind === "signature"
          ? "تم رفع التوقيع بنجاح."
          : "تم رفع الختم بنجاح."
      );
    } catch (e: any) {
      setError(errorText(e));
    } finally {
      setUploading(null);
    }
  };

  const save = async () => {
    setSaving(true);
    setError("");
    setMessage("");
    try {
      const payload = { ...form };
      delete payload.id;
      delete payload.company_id;
      delete payload.created_at;
      delete payload.updated_at;
      await api.post("/company-settings", payload);
      setMessage("تم حفظ هوية الطباعة والتذييلات بنجاح.");
      await load();
    } catch (e: any) {
      setError(errorText(e));
    } finally {
      setSaving(false);
    }
  };

  const setDefaults = () => {
    setForm((f: any) => ({
      ...f,
      invoice_footer: DEFAULT_INVOICE_FOOTER,
      report_footer: DEFAULT_REPORT_FOOTER,
    }));
    setMessage("تم وضع التذييلات الافتراضية في الحقول. اضغط حفظ لاعتمادها.");
  };

  const companyLine = useMemo(
    () => [form.print_phone, form.print_email, form.print_city].filter(Boolean).join(" • "),
    [form]
  );

  if (loading) return <div dir="rtl" className="p-8">جاري تحميل إعدادات الطباعة...</div>;

  return (
    <section dir="rtl" className="space-y-5 p-5 pb-16">
      <div className="rounded-3xl bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-6 text-white shadow-sm">
        <p className="text-sm text-blue-100">إعدادات الشركة / الطباعة</p>
        <h1 className="mt-1 text-2xl font-black">هوية الطباعة والفواتير</h1>
        <p className="mt-2 text-sm text-blue-100">
          ارفع الشعار والتوقيع والختم، واضبط بيانات الفاتورة والتذييلات، ثم عاين الشكل قبل اختبار الطباعة.
        </p>
      </div>

      {error && <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 font-bold text-rose-700">{error}</div>}
      {message && <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-700">{message}</div>}

      <div className="grid gap-5 xl:grid-cols-[1.05fr_.95fr]">
        <div className="space-y-5">
          <div className="rounded-3xl border bg-white p-5 shadow-sm">
            <h2 className="mb-4 text-lg font-black text-[#0B2A4A]">بيانات رأس الفاتورة</h2>
            <div className="grid gap-3 md:grid-cols-2">
              <Field label="اسم المنشأة في الطباعة" value={form.print_company_name} onChange={(v)=>setForm({...form,print_company_name:v})}/>
              <Field label="الهاتف" value={form.print_phone} onChange={(v)=>setForm({...form,print_phone:v})}/>
              <Field label="البريد الإلكتروني" value={form.print_email} onChange={(v)=>setForm({...form,print_email:v})}/>
              <Field label="المدينة" value={form.print_city} onChange={(v)=>setForm({...form,print_city:v})}/>
              <Field label="السجل التجاري" value={form.commercial_register} onChange={(v)=>setForm({...form,commercial_register:v})}/>
              <Field label="الرقم الضريبي" value={form.tax_number} onChange={(v)=>setForm({...form,tax_number:v})}/>
              <label className="md:col-span-2 space-y-1 text-sm font-bold text-slate-700">
                <span>العنوان</span>
                <textarea rows={3} className="w-full rounded-xl border p-3 outline-none focus:border-[#0B2A4A]" value={form.print_address||""} onChange={(e)=>setForm({...form,print_address:e.target.value})}/>
              </label>
            </div>
          </div>

          <div className="rounded-3xl border bg-white p-5 shadow-sm">
            <h2 className="mb-4 text-lg font-black text-[#0B2A4A]">شعار / توقيع / ختم</h2>
            <div className="grid gap-4 md:grid-cols-3">
              <UploadCard title="شعار الشركة" src={imageSrc("logo")} busy={uploading==="logo"} onFile={(f)=>uploadAsset("logo",f)}/>
              <UploadCard title="التوقيع" src={imageSrc("signature")} busy={uploading==="signature"} onFile={(f)=>uploadAsset("signature",f)}/>
              <UploadCard title="الختم" src={imageSrc("stamp")} busy={uploading==="stamp"} onFile={(f)=>uploadAsset("stamp",f)}/>
            </div>
            <p className="mt-3 text-xs text-slate-500">PNG / JPG / WEBP — حد الواجهة 5 MB. لا تضف Content-Type يدويًا؛ النظام يرسله كـ multipart/form-data تلقائيًا.</p>
          </div>

          <div className="rounded-3xl border bg-white p-5 shadow-sm">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-lg font-black text-[#0B2A4A]">تذييل المستندات</h2>
              <button type="button" onClick={setDefaults} className="rounded-xl border border-[#0B2A4A] px-4 py-2 text-sm font-black text-[#0B2A4A]">إرجاع التذييل الافتراضي</button>
            </div>
            <div className="grid gap-4 md:grid-cols-2">
              <label className="space-y-1 text-sm font-bold text-slate-700">
                <span>تذييل الفواتير</span>
                <textarea rows={5} className="w-full rounded-xl border p-3 outline-none focus:border-[#0B2A4A]" value={form.invoice_footer||""} onChange={(e)=>setForm({...form,invoice_footer:e.target.value})}/>
              </label>
              <label className="space-y-1 text-sm font-bold text-slate-700">
                <span>تذييل التقارير</span>
                <textarea rows={5} className="w-full rounded-xl border p-3 outline-none focus:border-[#0B2A4A]" value={form.report_footer||""} onChange={(e)=>setForm({...form,report_footer:e.target.value})}/>
              </label>
            </div>
          </div>

          <div className="flex flex-wrap gap-2">
            <button disabled={saving||!!uploading} onClick={save} className="rounded-xl bg-[#0B2A4A] px-6 py-3 font-black text-white disabled:opacity-50">{saving?"جاري الحفظ...":"حفظ هوية الطباعة"}</button>
            <button type="button" onClick={()=>window.open("/print/purchases/1","_blank")} className="rounded-xl border px-5 py-3 font-bold">فتح صفحة الطباعة للاختبار</button>
          </div>
        </div>

        <div className="xl:sticky xl:top-4 xl:self-start">
          <div className="rounded-3xl border bg-slate-100 p-4 shadow-sm">
            <div className="mb-3 font-black text-[#0B2A4A]">معاينة مباشرة</div>
            <div className="mx-auto min-h-[720px] max-w-[620px] rounded-xl bg-white p-7 shadow-sm">
              <div className="flex items-start justify-between gap-5 border-b-2 pb-4" style={{borderColor:form.primary_color||"#0B2A4A"}}>
                <div>
                  <h3 className="text-xl font-black" style={{color:form.primary_color||"#0B2A4A"}}>{form.print_company_name||"اسم المنشأة"}</h3>
                  <div className="mt-1 text-xs leading-6 text-slate-600">{form.print_address||"العنوان الوطني / عنوان المنشأة"}</div>
                  <div className="text-xs text-slate-600">{companyLine||"الهاتف • البريد الإلكتروني • المدينة"}</div>
                  <div className="text-xs text-slate-600">السجل التجاري: {form.commercial_register||"—"} • الرقم الضريبي: {form.tax_number||"—"}</div>
                </div>
                <div className="flex h-20 w-28 items-center justify-center rounded-lg border bg-white p-2">
                  {imageSrc("logo")?<img src={imageSrc("logo")} className="max-h-full max-w-full object-contain" alt="logo"/>:<span className="text-xs text-slate-400">الشعار</span>}
                </div>
              </div>

              <div className="py-6 text-center"><div className="text-xl font-black text-[#0B2A4A]">فاتورة ضريبية</div><div className="mt-1 text-xs text-slate-500">Tax Invoice</div></div>
              <div className="grid grid-cols-2 gap-2 text-xs">
                <PreviewBox label="رقم الفاتورة" value="MAIN-7-2026-000001"/><PreviewBox label="التاريخ" value="2026-08-19"/>
                <PreviewBox label="المورد / العميل" value="شركة الاختبار"/><PreviewBox label="العملة" value="SAR"/>
              </div>
              <div className="mt-4 overflow-hidden rounded-lg border text-xs"><div className="grid grid-cols-4 bg-slate-100 p-2 font-bold"><span>الصنف</span><span>الكمية</span><span>السعر</span><span>الإجمالي</span></div><div className="grid grid-cols-4 p-2"><span>حديد سكراب</span><span>980 KG</span><span>25.00</span><span>24,500.00</span></div></div>
              <div className="mr-auto mt-4 w-64 space-y-1 rounded-lg bg-slate-50 p-3 text-xs"><div className="flex justify-between"><span>قبل الضريبة</span><b>21,304.35</b></div><div className="flex justify-between"><span>VAT</span><b>3,195.65</b></div><div className="flex justify-between border-t pt-1 text-sm"><span>الإجمالي</span><b>24,500.00 SAR</b></div></div>

              <div className="mt-12 flex min-h-24 items-end justify-end gap-5">
                {imageSrc("signature")&&<img src={imageSrc("signature")} className="max-h-20 max-w-40 object-contain" alt="signature"/>}
                {imageSrc("stamp")&&<img src={imageSrc("stamp")} className="max-h-24 max-w-32 object-contain opacity-90" alt="stamp"/>}
              </div>
              <div className="mt-6 border-t pt-3 text-center text-[10px] leading-5 text-slate-500">{form.invoice_footer||DEFAULT_INVOICE_FOOTER}</div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

function Field({label,value,onChange}:{label:string;value:any;onChange:(v:string)=>void}){return <label className="space-y-1 text-sm font-bold text-slate-700"><span>{label}</span><input className="w-full rounded-xl border p-3 outline-none focus:border-[#0B2A4A]" value={value||""} onChange={(e)=>onChange(e.target.value)}/></label>}
function PreviewBox({label,value}:{label:string;value:string}){return <div className="rounded-lg border bg-slate-50 p-2"><div className="text-[10px] text-slate-500">{label}</div><b>{value}</b></div>}
function UploadCard({title,src,busy,onFile}:{title:string;src:string;busy:boolean;onFile:(f:File|null)=>void}){return <label className="block rounded-2xl border p-3"><div className="mb-2 font-black text-[#0B2A4A]">{title}</div><div className="flex h-32 items-center justify-center rounded-xl bg-slate-50 p-3">{src?<img src={src} className="max-h-full max-w-full object-contain" alt={title}/>:<span className="text-sm text-slate-400">لا يوجد ملف</span>}</div><input type="file" accept="image/png,image/jpeg,image/webp" className="mt-3 block w-full text-xs" disabled={busy} onChange={(e)=>onFile(e.target.files?.[0]||null)}/><div className="mt-2 text-xs text-slate-500">{busy?"جاري الرفع...":"يتم الرفع فور اختيار الملف"}</div></label>}
