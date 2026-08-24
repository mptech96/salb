"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../api";

export default function AuditLogsPage() {
  const [logs, setLogs] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);

  const [filters, setFilters] = useState({
    search: "",
    module_name: "",
    action_type: "",
  });

  const loadLogs = async () => {
    setLoading(true);
    try {
      const params: any = {};
      if (filters.search) params.search = filters.search;
      if (filters.module_name) params.module_name = filters.module_name;
      if (filters.action_type) params.action_type = filters.action_type;

      const res = await api.get("/audit-logs", { params });
      setLogs(res.data.data || []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadLogs();
  }, []);

  const modules = useMemo(() => {
    return Array.from(new Set(logs.map((x) => x.module_name).filter(Boolean)));
  }, [logs]);

  const exportCSV = () => {
    const rows = logs.map((log) => ({
      الوقت: log.created_at,
      الشركة: log.company_name || "-",
      الفرع: log.branch_name || "-",
      المستخدم: log.user_name || log.username || "النظام",
      الوحدة: log.module_name,
      العملية: log.action_type,
      الوصف: log.description || "-",
      IP: log.ip_address || "-",
    }));

    const csv =
      Object.keys(rows[0] || {}).join(",") +
      "\n" +
      rows.map((r) => Object.values(r).join(",")).join("\n");

    const blob = new Blob(["\uFEFF" + csv], {
      type: "text/csv;charset=utf-8;",
    });

    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "audit-logs.csv";
    a.click();
  };

  return (
    <section dir="rtl" className="space-y-5">
      <div className="rounded-3xl bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-6 text-white shadow-lg">
        <p className="text-sm text-blue-100">المراقبة والتتبع</p>
        <h1 className="mt-2 text-3xl font-black">سجل الأنشطة</h1>
        <p className="mt-2 text-sm text-blue-100">
          متابعة عمليات المستخدمين داخل الشركة: دخول، إضافة، تعديل، حذف، ورفع مرفقات.
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
        <Stat title="إجمالي السجلات" value={logs.length} />
        <Stat title="إضافات" value={logs.filter((x) => x.action_type === "CREATE").length} />
        <Stat title="تعديلات" value={logs.filter((x) => x.action_type === "UPDATE").length} />
        <Stat title="حذف" value={logs.filter((x) => x.action_type === "DELETE").length} />
      </div>

      <div className="rounded-3xl border bg-white p-4 shadow-sm">
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-5">
          <input
            className="rounded-2xl border bg-slate-50 p-4 lg:col-span-2"
            placeholder="بحث بالوصف أو المستخدم..."
            value={filters.search}
            onChange={(e) => setFilters({ ...filters, search: e.target.value })}
          />

          <select
            className="rounded-2xl border bg-slate-50 p-4"
            value={filters.module_name}
            onChange={(e) => setFilters({ ...filters, module_name: e.target.value })}
          >
            <option value="">كل الوحدات</option>
            {modules.map((m) => (
              <option key={m} value={m}>
                {m}
              </option>
            ))}
          </select>

          <select
            className="rounded-2xl border bg-slate-50 p-4"
            value={filters.action_type}
            onChange={(e) => setFilters({ ...filters, action_type: e.target.value })}
          >
            <option value="">كل العمليات</option>
            <option value="LOGIN">LOGIN</option>
            <option value="CREATE">CREATE</option>
            <option value="UPDATE">UPDATE</option>
            <option value="DELETE">DELETE</option>
            <option value="SUPPORT_ACCESS">SUPPORT_ACCESS</option>
          </select>

          <div className="flex gap-2">
            <button
              onClick={loadLogs}
              className="flex-1 rounded-2xl bg-[#0B2A4A] px-4 py-3 font-bold text-white"
            >
              بحث
            </button>

            <button
              onClick={exportCSV}
              className="flex-1 rounded-2xl bg-emerald-600 px-4 py-3 font-bold text-white"
            >
              CSV
            </button>
          </div>
        </div>
      </div>

      <div className="overflow-hidden rounded-3xl border bg-white shadow-sm">
        <div className="flex items-center justify-between border-b p-4">
          <h2 className="text-xl font-black text-[#0B2A4A]">آخر العمليات</h2>
          <span className="text-sm text-slate-500">
            {loading ? "جاري التحميل..." : `${logs.length} سجل`}
          </span>
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-[1200px] w-full text-right">
            <thead className="bg-slate-100 text-slate-700">
              <tr>
                <th className="p-4">الوقت</th>
                <th className="p-4">الشركة</th>
                <th className="p-4">الفرع</th>
                <th className="p-4">المستخدم</th>
                <th className="p-4">الوحدة</th>
                <th className="p-4">العملية</th>
                <th className="p-4">الوصف</th>
                <th className="p-4">IP</th>
              </tr>
            </thead>

            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={8} className="p-6 text-center">
                    جاري التحميل...
                  </td>
                </tr>
              ) : logs.length === 0 ? (
                <tr>
                  <td colSpan={8} className="p-6 text-center text-slate-500">
                    لا توجد سجلات نشاط
                  </td>
                </tr>
              ) : (
                logs.map((log) => (
                  <tr key={log.id} className="border-t hover:bg-slate-50">
                    <td className="p-4">{log.created_at}</td>
                    <td className="p-4">{log.company_name || "-"}</td>
                    <td className="p-4">{log.branch_name || "-"}</td>
                    <td className="p-4">
                      {log.user_name || log.username || "النظام"}
                    </td>
                    <td className="p-4">{log.module_name || "-"}</td>
                    <td className="p-4">
                      <span className={`rounded-full px-3 py-1 text-xs font-bold ${badgeColor(log.action_type)}`}>
                        {log.action_type}
                      </span>
                    </td>
                    <td className="p-4">{log.description || "-"}</td>
                    <td className="p-4">{log.ip_address || "-"}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </section>
  );
}

function Stat({ title, value }: any) {
  return (
    <div className="rounded-3xl bg-white p-5 shadow-sm">
      <div className="text-sm text-slate-500">{title}</div>
      <div className="mt-2 text-3xl font-black text-[#0B2A4A]">{value}</div>
    </div>
  );
}

function badgeColor(action: string) {
  if (action === "CREATE") return "bg-emerald-100 text-emerald-700";
  if (action === "UPDATE") return "bg-blue-100 text-blue-700";
  if (action === "DELETE") return "bg-rose-100 text-rose-700";
  if (action === "LOGIN") return "bg-purple-100 text-purple-700";
  return "bg-slate-100 text-slate-700";
}