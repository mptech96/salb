"use client";

import { useEffect, useState } from "react";
import api from "@/app/api";
import PrintHeader from "@/components/reports/PrintHeader";
import PrintFooter from "@/components/reports/PrintFooter";
import { locales, localeDirection, printText, printWhenReady, printVisible, resolvePrintOptions, type PrintLocale } from "@/lib/print-branding";
import { primaryButtonClassName, secondaryButtonClassName } from "@/components/ui/enterprise";

type Profile = Record<string, any>;

export default function PrintBrandingPreview() {
  const [profile,setProfile]=useState<Profile>({});
  const [locale,setLocale]=useState<PrintLocale>("ar");
  const [error,setError]=useState("");
  useEffect(()=>{let active=true;void api.get("/company-settings").then(response=>{if(active)setProfile(response.data?.data||{})}).catch(()=>{if(active)setError("تعذر تحميل إعدادات الطباعة.")});return()=>{active=false}},[]);
  const text=printText[locale];
  const options=resolvePrintOptions(profile.print_options,"report");
  const direction=localeDirection(locale);
  return <main dir={direction} className="min-h-screen bg-slate-100 p-3 print:bg-white print:p-0">
    <div className="no-print mx-auto mb-3 flex max-w-5xl flex-wrap items-center justify-between gap-2">
      <div className="flex gap-2 overflow-x-auto">{locales.map(item=><button key={item.code} type="button" onClick={()=>setLocale(item.code)} className={locale===item.code?primaryButtonClassName:secondaryButtonClassName}>{item.label}</button>)}</div>
      <button type="button" onClick={()=>void printWhenReady()} className={primaryButtonClassName}>{text.print}</button>
    </div>
    {error?<p role="alert" className="mx-auto max-w-5xl rounded-lg bg-rose-50 p-3 text-rose-800">{error}</p>:
      <article className={`sulb-print-area relative mx-auto bg-white shadow ${options.orientation==="landscape"?"max-w-[297mm]":"max-w-[210mm]"}`} style={{padding:`${Math.max(5,Math.min(30,Number(options.margin_mm)||12))}mm`,"--print-header-height":`${options.header_height_mm||28}mm`,"--print-footer-height":`${options.footer_height_mm||20}mm`} as React.CSSProperties}>
        <PrintHeader profile={{...profile,print_locale:locale,print_family:"report",phone:profile.print_phone,email:profile.print_email,city:profile.print_city,address:profile.print_address}} title={text.title}/>
        <section className="relative z-10">
          <div className="grid grid-cols-2 gap-2 text-sm">{printVisible(options,"document_date")&&<Box label={text.date} value="2026-08-31"/>}{printVisible(options,"document_number")&&<Box label={text.reference} value="TEST-2026-0001"/>}</div>
          <table className="mt-5 w-full border-collapse text-sm"><thead><tr>{[text.item,text.qty,text.price,text.total].map(label=><th className="border bg-slate-100 p-2" key={label}>{label}</th>)}</tr></thead><tbody>{Array.from({length:34},(_,index)=><tr key={index}><td className="border p-2">{locale==="ja"?`テスト品目 ${index+1}`:locale==="en"?`Test item ${index+1}`:locale==="ur"?`ٹیسٹ آئٹم ${index+1}`:`صنف اختبار ${index+1}`}</td><td className="border p-2 tabular-nums">{index+1}</td><td className="border p-2 tabular-nums">25.00</td><td className="border p-2 tabular-nums">{((index+1)*25).toFixed(2)}</td></tr>)}</tbody><tfoot><tr><td colSpan={3} className="border p-2 font-bold">{text.total}</td><td className="border p-2 font-bold">14,875.00</td></tr></tfoot></table>
        </section>
        <PrintFooter profile={{...profile,print_locale:locale,print_family:"report"}} family="report"/>
      </article>}
    <style jsx global>{`@media print{@page{size:A4 ${options.orientation==="landscape"?"landscape":"portrait"};margin:10mm}nav,aside,.no-print{display:none!important}.sulb-print-area{box-shadow:none!important}thead{display:table-header-group}tr{break-inside:avoid}}`}</style>
  </main>;
}

function Box({label,value}:{label:string;value:string}) { return <div className="rounded border p-2"><span className="block text-xs text-slate-500">{label}</span><b>{value}</b></div> }
