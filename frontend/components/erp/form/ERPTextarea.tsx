"use client";

export default function ERPTextarea({
  label,
  value,
  onChange,
  disabled = false,
  placeholder = "",
}: any) {
  return (
    <label className="block">
      {label && <div className="mb-1 text-sm font-bold text-slate-600">{label}</div>}

      <textarea
        disabled={disabled}
        placeholder={placeholder}
        value={value ?? ""}
        onChange={(e) => onChange(e.target.value)}
        className="min-h-[110px] w-full rounded-2xl border bg-slate-50 p-3 outline-none focus:border-[#0B2A4A] disabled:opacity-60"
      />
    </label>
  );
}