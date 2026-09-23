"use client";
import { useEffect, useState, type CSSProperties } from "react";
import api from "@/app/api";
import { registerPrintAsset, type PrintAsset } from "@/lib/print-branding";

export default function BrandingAssetImage({ asset, version, scopeKey, className, alt, enabled=true, style }: { asset: PrintAsset; version?: string | number; scopeKey?: string | number | null; className?: string; alt: string; enabled?: boolean; style?: CSSProperties }) {
  const identity=`${scopeKey ?? ""}:${asset}:${version ?? ""}`;
  const [image,setImage]=useState<{identity:string;url:string}|null>(null);
  useEffect(()=>{let active=true,url="";if(!enabled){setImage(null);return()=>{active=false}}const request=api.get(`/company-settings/assets/${asset}`,{responseType:"blob"}).then(r=>{url=URL.createObjectURL(r.data);if(active)setImage({identity,url})}).catch(()=>{if(active)setImage(null)});void registerPrintAsset(request);return()=>{active=false;if(url)URL.revokeObjectURL(url)}},[asset,version,enabled,scopeKey,identity]);
  return enabled && image?.identity===identity ? <img src={image.url} className={className} alt={alt} style={style}/> : null;
}
