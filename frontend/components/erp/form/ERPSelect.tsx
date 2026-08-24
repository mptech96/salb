"use client";

export default function ERPSelect({
  label,
  value,
  onChange,
  options = [],
  nameKey = "name",
  valueKey = "id",
  disabled = false,
  placeholder = "اختر",
}: any) {
  return (
    <label className="block">
      {label && <div className="mb-1 text-sm font-bold text-slate-600">{label}</div>}

      <select
        disabled={disabled}
        value={value ?? ""}
        onChange={(e) => onChange(e.target.value)}
        className="w-full rounded-2xl border bg-slate-50 p-3 outline-none focus:border-[#0B2A4A] disabled:opacity-60"
      >
        <option value="">{placeholder}</option>
        {options.map((x: any) => (
          <option key={x[valueKey]} value={x[valueKey]}>
            {x[nameKey] || x.name || x.title || x[valueKey]}
          </option>
        ))}
      </select>
    </label>
  );
}