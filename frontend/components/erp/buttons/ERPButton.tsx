"use client";

export default function ERPButton({
  children,
  onClick,
  type = "primary",
  disabled = false,
  className = "",
}: any) {
  const styles: any = {
    primary: "bg-[#0B2A4A] text-white hover:bg-[#123D68]",
    success: "bg-emerald-600 text-white hover:bg-emerald-700",
    danger: "bg-rose-600 text-white hover:bg-rose-700",
    secondary: "bg-slate-200 text-slate-800 hover:bg-slate-300",
    purple: "bg-purple-700 text-white hover:bg-purple-800",
  };

  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className={`rounded-2xl px-5 py-3 text-sm font-black shadow-sm disabled:cursor-not-allowed disabled:opacity-50 ${styles[type]} ${className}`}
    >
      {children}
    </button>
  );
}