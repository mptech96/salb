"use client";

export default function ERPToolbar({ children }: any) {
  return (
    <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        {children}
      </div>
    </div>
  );
}