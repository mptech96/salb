"use client";

type Props = {
  profile?: any;
  title: string;
  filters?: { from_date?: string; to_date?: string };
};

export default function PrintHeader({ profile, title, filters }: Props) {
  return (
    <div className="mb-5 border-b-2 border-[#0B2A4A] pb-4">
      <div className="flex items-start justify-between gap-5">
        <div className="flex items-start gap-4">
          {profile?.logo_url ? (
            <img src={profile.logo_url} alt="الشعار" className="h-16 w-16 object-contain" />
          ) : null}
          <div>
            <div className="text-xl font-black text-[#0B2A4A]">
              {profile?.company_name || "صلب ERP"}
            </div>
            <div className="mt-1 text-sm text-slate-500">
              {[profile?.phone, profile?.email, profile?.city].filter(Boolean).join(" • ")}
            </div>
            {profile?.address ? <div className="text-xs text-slate-500">{profile.address}</div> : null}
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
    </div>
  );
}
