"use client";

export default function ERPHeader({
  title,
  subtitle,
  actions,
}: any) {
  return (
    <div className="rounded-3xl bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-5 text-white shadow-lg sm:p-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          {subtitle && <p className="text-sm font-semibold text-blue-100">{subtitle}</p>}
          <h1 className="mt-2 text-2xl font-black sm:text-3xl">{title}</h1>
        </div>

        {actions && (
          <div className="flex flex-wrap gap-2">
            {actions}
          </div>
        )}
      </div>
    </div>
  );
}