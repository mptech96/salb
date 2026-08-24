"use client";

import { useRouter } from "next/navigation";

export default function FixedAssetsLandingPage() {
  const router = useRouter();

  return (
    <div
      dir="rtl"
      className="flex min-h-[70vh] items-center justify-center p-6"
    >
      <div className="w-full max-w-3xl rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <h1 className="text-3xl font-black text-[#0B2A4A]">
          إدارة الأصول الثابتة
        </h1>

        <p className="mt-3 text-sm font-semibold leading-7 text-slate-500">
          اختر شاشة العمل المطلوبة لإدارة الأصول والفئات والتقارير.
        </p>

        <div className="mt-8 grid grid-cols-1 gap-4 md:grid-cols-3">
          <button
            type="button"
            onClick={() =>
              router.push("/fixed-assets/categories")
            }
            className="rounded-2xl border border-slate-200 bg-slate-50 p-5 font-black text-slate-800 transition hover:bg-slate-100"
          >
            فئات الأصول
          </button>

          <button
            type="button"
            onClick={() =>
              router.push("/fixed-assets/assets")
            }
            className="rounded-2xl border border-slate-200 bg-slate-50 p-5 font-black text-slate-800 transition hover:bg-slate-100"
          >
            سجل الأصول
          </button>

          <button
            type="button"
            onClick={() =>
              router.push("/fixed-assets/workspace")
            }
            className="rounded-2xl border border-slate-200 bg-slate-50 p-5 font-black text-slate-800 transition hover:bg-slate-100"
          >
            مساحة إدارة الأصول
          </button>
        </div>
      </div>
    </div>
  );
}