"use client";

export default function ERPInput({
  label,
  value,
  onChange,
  type = "text",
  disabled = false,
  placeholder = "",
}: any) {
  const isNumber = type === "number";

  return (
    <label className="block">
      {label && <div className="mb-1 text-sm font-bold text-slate-600">{label}</div>}

      <input
        disabled={disabled}
        type={type}
        inputMode={isNumber ? "decimal" : undefined}
        step={isNumber ? "0.001" : undefined}
        min={isNumber ? "0" : undefined}
        placeholder={placeholder}
        className="w-full rounded-2xl border bg-slate-50 p-3 outline-none focus:border-[#0B2A4A] disabled:opacity-60"
        value={value ?? ""}
        onWheel={(e: any) => isNumber && e.currentTarget.blur()}
        onChange={(e) => onChange(isNumber ? Number(e.target.value) : e.target.value)}
      />
    </label>
  );
}