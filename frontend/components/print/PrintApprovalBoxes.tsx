"use client";

import BrandingAssetImage from "@/components/print/BrandingAssetImage";
import { printVisible, resolvePrintOptions, type PrintFamily } from "@/lib/print-branding";

export default function PrintApprovalBoxes({ profile, family }: { profile?: any; family: PrintFamily }) {
  const options = resolvePrintOptions(profile?.print_options, family);
  const signature = printVisible(options, "signature") && Boolean(profile?.signature_url || profile?.signature_path || profile?.has_signature);
  const stamp = printVisible(options, "stamp") && Boolean(profile?.stamp_url || profile?.stamp_path || profile?.has_stamp);
  return <section className="print-approval-boxes mt-6 grid break-inside-avoid grid-cols-2 gap-6" aria-label="التوقيع والختم">
    <div className="print-approval-box flex min-h-[34mm] flex-col items-center justify-between rounded-lg border border-dashed border-slate-400 p-3">
      <b className="text-sm text-slate-700">التوقيع</b>
      {signature ? <BrandingAssetImage asset="signature" scopeKey={profile?.company_id} enabled alt="صورة التوقيع المحفوظة؛ ليست توقيعًا إلكترونيًا" className="max-h-[20mm] max-w-[55mm] object-contain" /> : <span aria-hidden="true" className="h-[20mm]" />}
      <span className="w-4/5 border-t border-slate-400" />
    </div>
    <div className="print-approval-box flex min-h-[34mm] flex-col items-center justify-between rounded-lg border border-dashed border-slate-400 p-3">
      <b className="text-sm text-slate-700">الختم</b>
      {stamp ? <BrandingAssetImage asset="stamp" scopeKey={profile?.company_id} enabled alt="صورة الختم المحفوظة؛ ليست اعتمادًا إلكترونيًا" className="max-h-[22mm] max-w-[45mm] object-contain" /> : <span aria-hidden="true" className="h-[22mm]" />}
      <span className="w-4/5 border-t border-slate-400" />
    </div>
  </section>;
}
