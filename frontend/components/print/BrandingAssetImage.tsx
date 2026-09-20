"use client";
import { useEffect, useState } from "react";
import api from "@/app/api";
import { registerPrintAsset, type PrintAsset } from "@/lib/print-branding";

export default function BrandingAssetImage({ asset, version, className, alt, enabled=true }: { asset: PrintAsset; version?: string | number; className?: string; alt: string; enabled?: boolean }) {
  const [src,setSrc]=useState("");
  useEffect(()=>{let active=true,url="";if(!enabled){setSrc("");return()=>{active=false}}const request=api.get(`/company-settings/assets/${asset}`,{responseType:"blob"}).then(r=>{url=URL.createObjectURL(r.data);if(active)setSrc(url)}).catch(()=>{if(active)setSrc("")});void registerPrintAsset(request);return()=>{active=false;if(url)URL.revokeObjectURL(url)}},[asset,version,enabled]);
  return src ? <img src={src} className={className} alt={alt}/> : null;
}
