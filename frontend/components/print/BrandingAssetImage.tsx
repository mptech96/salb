"use client";
import { useEffect, useState } from "react";
import api from "@/app/api";
import type { PrintAsset } from "@/lib/print-branding";

export default function BrandingAssetImage({ asset, version, className, alt }: { asset: PrintAsset; version?: string | number; className?: string; alt: string }) {
  const [src,setSrc]=useState("");
  useEffect(()=>{let active=true,url="";void api.get(`/company-settings/assets/${asset}`,{responseType:"blob"}).then(r=>{url=URL.createObjectURL(r.data);if(active)setSrc(url)}).catch(()=>{if(active)setSrc("")});return()=>{active=false;if(url)URL.revokeObjectURL(url)}},[asset,version]);
  return src ? <img src={src} className={className} alt={alt}/> : null;
}
