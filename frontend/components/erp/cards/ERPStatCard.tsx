"use client";

interface Props {
  title: string;
  value: string | number;
  color?: string;
}

export default function ERPStatCard({
  title,
  value,
  color = "#0B2A4A",
}: Props) {
  return (
    <div className="rounded-3xl bg-white border border-slate-200 p-5 shadow-sm">
      <div className="text-sm font-bold text-slate-500">
        {title}
      </div>

      <div
        className="mt-3 text-3xl font-black"
        style={{ color }}
      >
        {value}
      </div>
    </div>
  );
}