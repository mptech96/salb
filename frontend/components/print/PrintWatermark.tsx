"use client";

import BrandingAssetImage from "@/components/print/BrandingAssetImage";
import { resolvePrintOptions, type PrintFamily } from "@/lib/print-branding";

export default function PrintWatermark({ profile, family = "report" }: { profile?: any; family?: PrintFamily }) {
  const options = resolvePrintOptions(profile?.print_options, family);
  const mark = options.watermark;
  if (!mark?.enabled || options.visibility?.watermark === false) return null;
  const opacity = Math.max(0.03, Math.min(0.3, Number(mark.opacity ?? 0.12)));
  const size = Math.max(12, Math.min(160, Number(mark.size ?? 48)));
  const position = mark.position === "TOP" ? "28%" : mark.position === "BOTTOM" ? "72%" : "50%";
  const style = { opacity, top: position, transform: `translate(-50%, -50%) rotate(${Math.max(-70, Math.min(70, Number(mark.angle ?? -30)))}deg)` };
  return <div aria-hidden="true" className={`pointer-events-none left-1/2 z-0 select-none ${mark.pages === "FIRST" ? "absolute" : "fixed"}`} style={style}>
    {mark.mode === "IMAGE" ? <BrandingAssetImage asset="watermark" scopeKey={profile?.company_id} enabled={Boolean(profile?.watermark_url || profile?.has_watermark || profile?.watermark_path)} alt="" className="max-h-[65mm] max-w-[140mm] object-contain" /> :
      <span className="block whitespace-nowrap font-bold" style={{ color: /^#[0-9a-f]{6}$/i.test(mark.color || "") ? mark.color : "#64748b", fontSize: `${size}px` }}>{mark.text || profile?.print_company_name || ""}</span>}
  </div>;
}
