"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function LegacyCompaniesRedirect() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/system-center/companies");
  }, [router]);

  return (
    <div className="flex min-h-[50vh] items-center justify-center font-black text-[#0B2A4A]">
      جاري الانتقال إلى إدارة الشركات...
    </div>
  );
}
