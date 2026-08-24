"use client";

export default function NoAccessPage() {
  return (
    <section
      dir="rtl"
      className="mx-auto flex min-h-[65vh] max-w-2xl items-center justify-center px-4"
    >
      <div className="w-full rounded-[32px] border border-amber-200 bg-white p-7 text-center shadow-xl sm:p-10">
        <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-amber-100 text-3xl font-black text-amber-700">
          !
        </div>
        <h1 className="mt-5 text-3xl font-black text-[#0B2A4A]">
          لا توجد شاشات متاحة لهذا الحساب
        </h1>
        <p className="mt-4 text-sm font-medium leading-7 text-slate-600">
          تم تسجيل الدخول بنجاح، لكن الدور الحالي لا يملك أي صلاحية فعالة داخل
          بوابة الشركة. راجع مدير الشركة لتحديد الصلاحيات المناسبة.
        </p>
      </div>
    </section>
  );
}
