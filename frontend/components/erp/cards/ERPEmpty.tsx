"use client";

export default function ERPEmpty({
  title = "لا توجد بيانات",
  text = "لم يتم العثور على سجلات لعرضها.",
  action,
}: any) {
  return (
    <div className="rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
      <div className="text-xl font-black text-slate-700">{title}</div>
      <div className="mt-2 text-sm font-semibold text-slate-500">{text}</div>
      {action && <div className="mt-5 flex justify-center">{action}</div>}
    </div>
  );
}