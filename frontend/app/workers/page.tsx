"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../api";
import useSystemFeedback from "@/components/common/useSystemFeedback";
import { PageHeader, primaryButtonClassName, fieldClassName } from "@/components/ui/enterprise";
import { EnterpriseFilterBar, EnterpriseTable } from "@/components/design-system/EnterpriseWorkspace";
import { LifecycleStrip, ModuleLinks, WorkspaceNotice } from "@/components/design-system/LifecycleWorkspace";

export default function WorkersPage() {
  const [workers, setWorkers] = useState<any[]>([]);
  const [selected, setSelected] = useState<any>(null);
  const [workerDetails, setWorkerDetails] = useState<any>(null);
  const [showForm, setShowForm] = useState(false);
  const [activeTab, setActiveTab] = useState("INFO");
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [jobFilter, setJobFilter] = useState("");

  const [form, setForm] = useState(defaultWorker());
  const [loan, setLoan] = useState({ loan_date: today(), amount: "", payment_method: "CASH", notes: "" });
  const [commission, setCommission] = useState({ commission_date: today(), amount: "", status: "PENDING", notes: "" });
  const [attendance, setAttendance] = useState({ attendance_date: today(), check_in: "", check_out: "", work_hours: "", overtime_hours: "", status: "PRESENT", notes: "" });
  const [saving, setSaving] = useState(false);
  const { notify, requestConfirmation, feedbackDialog } = useSystemFeedback();

  useEffect(() => {
    loadWorkers();
  }, []);

  async function loadWorkers() {
  try {
    const res = await api.get("/workers");
    setWorkers(Array.isArray(res.data.data) ? res.data.data : []);
  } catch (e: any) {
    notify(e?.response?.data?.message || "فشل تحميل العمال", "error");
    setWorkers([]);
  }
}

  async function openWorker(id: number) {
    const res = await api.get(`/workers/${id}`);
    setSelected(res.data.data.worker);
    setWorkerDetails(res.data.data);
    setForm({ ...defaultWorker(), ...res.data.data.worker });
    setShowForm(true);
    setActiveTab("INFO");
  }

  function openNew() {
    setSelected(null);
    setWorkerDetails(null);
    setForm(defaultWorker());
    setShowForm(true);
    setActiveTab("INFO");
  }

  async function saveWorker() {
    if (saving) return;
    if (!form.worker_name) return notify("اكتب اسم العامل", "warning");

    const payload = {
      ...form,
      salary_rate: Number(form.salary_rate || 0),
    };

    setSaving(true);
    try {
      if (selected) {
        await api.put(`/workers/${selected.id}`, payload);
        notify("تم تعديل العامل", "success");
      } else {
        await api.post("/workers", payload);
        notify("تم إنشاء العامل", "success");
      }

      setShowForm(false);
      await loadWorkers();
    } catch (e: any) {
      notify(e?.response?.data?.message || "فشل حفظ العامل", "error");
    } finally {
      setSaving(false);
    }
  }

  function deleteWorker(id: number) {
    requestConfirmation("حذف العامل؟", async () => {
      await api.delete(`/workers/${id}`);
      await loadWorkers();
    }, "تأكيد حذف العامل");
  }

  async function saveLoan() {
    if (!selected || saving) return;
    if (!loan.amount) return notify("أدخل مبلغ السلفة", "warning");

    setSaving(true);
    try {
      await api.post(`/workers/${selected.id}/loans`, {
        ...loan,
        amount: Number(loan.amount),
      });

      notify("تم حفظ السلفة", "success");
      setLoan({ loan_date: today(), amount: "", payment_method: "CASH", notes: "" });
      await openWorker(selected.id);
    } catch (e: any) {
      notify(e?.response?.data?.message || "فشل حفظ السلفة", "error");
    } finally {
      setSaving(false);
    }
  }

 async function saveCommission() {
  if (!selected || saving) return;
  if (!commission.amount || Number(commission.amount) <= 0) {
    return notify("أدخل مبلغ العمولة", "warning");
  }

  const payload = {
    commission_date: commission.commission_date,
    amount: Number(commission.amount),
    status: commission.status || "PENDING",
    notes: commission.notes || "",
  };

  setSaving(true);
  try {
    await api.post(`/workers/${selected.id}/commissions`, payload);
    notify("تم حفظ العمولة", "success");

    setCommission({
      commission_date: today(),
      amount: "",
      status: "PENDING",
      notes: "",
    });

    await openWorker(selected.id);
  } catch (e: any) {
    notify(e?.response?.data?.message || "فشل حفظ العمولة", "error");
  } finally {
    setSaving(false);
  }
}
function approveCommission(id: number) {
  requestConfirmation("اعتماد العمولة وإنشاء قيد استحقاق؟", async () => {
    try {
      await api.post(`/workers/commissions/${id}/approve`);
      notify("تم اعتماد العمولة وإنشاء القيد", "success");
      await openWorker(selected.id);
    } catch (e: any) {
      notify(e?.response?.data?.message || "فشل اعتماد العمولة", "error");
    }
  }, "تأكيد اعتماد العمولة");
}

function payCommission(id: number) {
  requestConfirmation("دفع العمولة وإنشاء سند صرف وقيد دفع؟", async () => {
    try {
      await api.post(`/workers/commissions/${id}/pay`);
      notify("تم دفع العمولة", "success");
      await openWorker(selected.id);
    } catch (e: any) {
      notify(e?.response?.data?.message || "فشل دفع العمولة", "error");
    }
  }, "تأكيد دفع العمولة");
}

  async function saveAttendance() {
    if (!selected || saving) return;

    setSaving(true);
    try {
      await api.post(`/workers/${selected.id}/attendance`, {
        ...attendance,
        work_hours: Number(attendance.work_hours || 0),
        overtime_hours: Number(attendance.overtime_hours || 0),
      });

      notify("تم حفظ الحضور", "success");
      setAttendance({ attendance_date: today(), check_in: "", check_out: "", work_hours: "", overtime_hours: "", status: "PRESENT", notes: "" });
      await openWorker(selected.id);
    } catch (e: any) {
      notify(e?.response?.data?.message || "فشل حفظ الحضور", "error");
    } finally {
      setSaving(false);
    }
  }

  const filtered = useMemo(() => {
    return workers.filter((w) => (!statusFilter || w.worker_status === statusFilter) && (!jobFilter || w.job_title === jobFilter) &&
      `${w.worker_name || ""} ${w.phone || ""} ${w.employee_no || ""} ${w.job_title || ""}`
        .toLowerCase()
        .includes(search.toLowerCase())
    );
  }, [workers, search, statusFilter, jobFilter]);

  return (
    <section dir="rtl" className="space-y-5">
      <PageHeader title="مركز العمال والموظفين" description="ملف العامل والراتب والسلف والعمولات والحضور من مساحة عمل موحدة." breadcrumbs={[{label:"الرئيسية",href:"/"},{label:"الموارد البشرية"}]} actions={<button type="button" onClick={openNew} className={primaryButtonClassName}>+ عامل جديد</button>} />

      <LifecycleStrip title="دورة الموظف والراتب" steps={[{label:"ملف الموظف"},{label:"الوظيفة والفرع"},{label:"الحضور"},{label:"السلف والعمولات"},{label:"مسير الرواتب"},{label:"الاعتماد والصرف"}]}/>
      <ModuleLinks links={[{href:"/payroll",label:"مسيرات الرواتب",description:"مراجعة المسيرات والأسطر والاعتماد والصرف."},{href:"/workers",label:"ملفات الموظفين",description:"البيانات الوظيفية والحضور والسلف والعمولات."}]}/>
      <WorkspaceNotice>القيم المالية الظاهرة في ملف الموظف ومسير الرواتب مصدرها الخادم. لا تعيد الواجهة احتساب الرواتب أو الاستقطاعات.</WorkspaceNotice>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
        <Stat title="عدد العمال" value={workers.length} />
        <Stat title="النشطون" value={workers.filter((x) => x.worker_status === "ACTIVE").length} />
        <Stat title="رواتب شهرية" value={workers.filter((x) => x.salary_type === "MONTHLY").length} />
        <Stat title="رواتب يومية/ساعة" value={workers.filter((x) => ["DAILY", "HOURLY"].includes(String(x.salary_type || ""))).length} />
      </div>

      <EnterpriseFilterBar>
        <input
          className={fieldClassName}
          placeholder="بحث بالاسم أو رقم العامل أو الجوال أو الوظيفة..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <select className={fieldClassName} value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}><option value="">كل الحالات</option><option value="ACTIVE">نشط</option><option value="INACTIVE">غير نشط</option><option value="ENDED">منتهي</option></select>
        <select className={fieldClassName} value={jobFilter} onChange={(e) => setJobFilter(e.target.value)}><option value="">كل الوظائف</option>{Array.from(new Set(workers.map((worker) => String(worker.job_title || "")).filter(Boolean))).map((job) => <option key={job} value={job}>{job}</option>)}</select>
      </EnterpriseFilterBar>

      <div className="overflow-hidden rounded-3xl border bg-white shadow-sm">
        <div className="border-b p-4">
          <h2 className="text-xl font-black text-[#0B2A4A]">قائمة العمال</h2>
        </div>

        <EnterpriseTable minWidth={1100}>
            <thead className="bg-slate-100 text-slate-700">
              <tr>
                <th className="p-4">رقم العامل</th>
                <th className="p-4">الاسم</th>
                <th className="p-4">الجوال</th>
                <th className="p-4">الوظيفة</th>
                <th className="p-4">نوع الراتب</th>
                <th className="p-4">الأجر</th>
                <th className="p-4">الحالة</th>
                <th className="p-4">الإجراءات</th>
              </tr>
            </thead>

            <tbody>
              {filtered.length === 0 ? (
                <tr>
                  <td colSpan={8} className="p-6 text-center text-slate-500">لا توجد بيانات</td>
                </tr>
              ) : (
                filtered.map((w) => (
                  <tr key={w.id} className="border-t hover:bg-slate-50">
                    <td className="p-4 font-bold text-[#0B2A4A]">{w.employee_no || "-"}</td>
                    <td className="p-4 font-black">{w.worker_name}</td>
                    <td className="p-4">{w.phone || "-"}</td>
                    <td className="p-4">{w.job_title || "-"}</td>
                    <td className="p-4">{salaryTypeLabel(w.salary_type)}</td>
                    <td className="p-4 font-bold">{money(w.salary_rate)}</td>
                    <td className="p-4">
                      <span className={`rounded-full px-3 py-1 text-xs font-bold ${w.worker_status === "ACTIVE" ? "bg-emerald-100 text-emerald-700" : "bg-slate-100 text-slate-600"}`}>
                        {w.worker_status === "ACTIVE" ? "نشط" : "غير نشط"}
                      </span>
                    </td>
                    <td className="p-4">
                      <div className="flex gap-2">
                        <button onClick={() => openWorker(w.id)} className="rounded-xl bg-blue-700 px-3 py-2 text-sm font-bold text-white">فتح</button>
                        <button onClick={() => deleteWorker(w.id)} className="rounded-xl bg-rose-600 px-3 py-2 text-sm font-bold text-white">حذف</button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
        </EnterpriseTable>
      </div>

      {showForm && (
        <div className="fixed inset-0 z-[100] bg-slate-900/50 backdrop-blur-sm">
          <div role="dialog" aria-modal="true" aria-label={selected ? `مركز العامل: ${selected.worker_name}` : "عامل جديد"} className="absolute inset-y-0 left-0 w-full max-w-[900px] overflow-y-auto bg-white shadow-2xl">
            <div className="sticky top-0 z-10 rounded-t-3xl border-b bg-white p-4">
              <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                  <h2 className="text-2xl font-black text-[#0B2A4A]">
                    {selected ? `مركز العامل: ${selected.worker_name}` : "عامل جديد"}
                  </h2>
                  <p className="text-sm text-slate-500">ملف العامل الكامل والعمليات المرتبطة به.</p>
                </div>

                <div className="flex flex-wrap gap-2">
                  <button onClick={saveWorker} className="rounded-2xl bg-[#0B2A4A] px-5 py-3 font-bold text-white">
                    حفظ البيانات
                  </button>
                  <button onClick={() => setShowForm(false)} className="rounded-2xl bg-slate-200 px-5 py-3 font-bold">
                    إغلاق
                  </button>
                </div>
              </div>

              <div className="mt-4 flex flex-wrap gap-2">
                {[
                  ["INFO", "البيانات"],
                  ["LOANS", "السلف"],
                  ["COMMISSIONS", "العمولات"],
                  ["ATTENDANCE", "الحضور"],
                  ["SALARY", "الرواتب"],
                  ["STATEMENT", "كشف الحساب"],
                ].map(([key, label]) => (
                  <button
                    key={key}
                    onClick={() => setActiveTab(key)}
                    className={`rounded-2xl px-4 py-2 text-sm font-bold ${
                      activeTab === key ? "bg-[#0B2A4A] text-white" : "bg-slate-100 text-slate-700"
                    }`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>

            <div className="p-5">
              {activeTab === "INFO" && (
                <div className="space-y-5">
                  <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <Input label="رقم العامل" value={form.employee_no} onChange={(v: any) => setForm({ ...form, employee_no: v })} />
                    <Input label="اسم العامل *" value={form.worker_name} onChange={(v: any) => setForm({ ...form, worker_name: v })} />
                    <Input label="الجوال" value={form.phone} onChange={(v: any) => setForm({ ...form, phone: v })} />
                    <Input label="البريد" value={form.email} onChange={(v: any) => setForm({ ...form, email: v })} />

                    <Input label="الوظيفة" value={form.job_title} onChange={(v: any) => setForm({ ...form, job_title: v })} />
                    <Input label="القسم" value={form.department} onChange={(v: any) => setForm({ ...form, department: v })} />

                    <Select label="نوع الراتب" value={form.salary_type} onChange={(v: any) => setForm({ ...form, salary_type: v })} options={[
                      { id: "HOURLY", name: "بالساعة" },
                      { id: "DAILY", name: "باليوم" },
                      { id: "WEEKLY", name: "بالأسبوع" },
                      { id: "MONTHLY", name: "بالشهر" },
                    ]} />

                    <Input type="number" label="الأجر" value={form.salary_rate} onChange={(v: any) => setForm({ ...form, salary_rate: v })} />

                    <Input type="date" label="تاريخ التعيين" value={form.hire_date} onChange={(v: any) => setForm({ ...form, hire_date: v })} />
                    <Input type="date" label="تاريخ الانتهاء" value={form.end_date} onChange={(v: any) => setForm({ ...form, end_date: v })} />

                    <Input label="رقم الهوية" value={form.national_id} onChange={(v: any) => setForm({ ...form, national_id: v })} />
                    <Input label="رقم الإقامة" value={form.iqama_number} onChange={(v: any) => setForm({ ...form, iqama_number: v })} />
                    <Input label="رقم الجواز" value={form.passport_number} onChange={(v: any) => setForm({ ...form, passport_number: v })} />
                    <Input label="الجنسية" value={form.nationality} onChange={(v: any) => setForm({ ...form, nationality: v })} />

                    <Input label="البنك" value={form.bank_name} onChange={(v: any) => setForm({ ...form, bank_name: v })} />
                    <Input label="IBAN" value={form.iban} onChange={(v: any) => setForm({ ...form, iban: v })} />

                    <Input label="جهة الطوارئ" value={form.emergency_contact} onChange={(v: any) => setForm({ ...form, emergency_contact: v })} />
                    <Input label="جوال الطوارئ" value={form.emergency_phone} onChange={(v: any) => setForm({ ...form, emergency_phone: v })} />

                    <Select label="نوع العقد" value={form.contract_type} onChange={(v: any) => setForm({ ...form, contract_type: v })} options={[
                      { id: "FULL_TIME", name: "دوام كامل" },
                      { id: "PART_TIME", name: "دوام جزئي" },
                      { id: "TEMP", name: "مؤقت" },
                    ]} />

                    <Select label="الحالة" value={form.worker_status} onChange={(v: any) => setForm({ ...form, worker_status: v })} options={[
                      { id: "ACTIVE", name: "نشط" },
                      { id: "INACTIVE", name: "غير نشط" },
                      { id: "ENDED", name: "منتهي" },
                    ]} />
                  </div>

                  <textarea
                    className="w-full rounded-2xl border bg-slate-50 p-4"
                    placeholder="ملاحظات"
                    value={form.notes || ""}
                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                  />
                </div>
              )}

              {activeTab === "LOANS" && (
                <TabBox title="السلف">
                  {!selected ? <EmptyNeedSave /> : (
                    <>
                      <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
                        <Input type="date" label="تاريخ السلفة" value={loan.loan_date} onChange={(v: any) => setLoan({ ...loan, loan_date: v })} />
                        <Input type="number" label="المبلغ" value={loan.amount} onChange={(v: any) => setLoan({ ...loan, amount: v })} />
                        <Select label="طريقة الدفع" value={loan.payment_method} onChange={(v: any) => setLoan({ ...loan, payment_method: v })} options={[
                          { id: "CASH", name: "نقد" },
                          { id: "BANK", name: "تحويل بنكي" },
                          { id: "CARD", name: "بطاقة" },
                        ]} />
                        <button onClick={saveLoan} className="rounded-2xl bg-[#0B2A4A] px-5 py-3 font-bold text-white">حفظ السلفة</button>
                      </div>
                      <textarea className="mt-3 w-full rounded-2xl border bg-slate-50 p-4" placeholder="ملاحظات" value={loan.notes} onChange={(e) => setLoan({ ...loan, notes: e.target.value })} />
                      <MiniTable rows={workerDetails?.loans || []} cols={["loan_date", "amount", "payment_method", "notes"]} />
                    </>
                  )}
                </TabBox>
              )}

             {activeTab === "COMMISSIONS" && (
  <TabBox title="العمولات">
    {!selected ? <EmptyNeedSave /> : (
      <>
        <div className="rounded-2xl bg-blue-50 p-4 text-sm font-bold text-[#0B2A4A]">
          معلقة = بدون قيد، معتمدة = قيد استحقاق، مدفوعة = سند صرف + قيد دفع.
        </div>

        <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">
          <Input
            type="date"
            label="تاريخ العمولة"
            value={commission.commission_date}
            onChange={(v: any) => setCommission({ ...commission, commission_date: v })}
          />

          <Input
            type="number"
            label="المبلغ"
            value={commission.amount}
            onChange={(v: any) => setCommission({ ...commission, amount: v })}
          />

          <Select
            label="الحالة عند الإضافة"
            value={commission.status}
            onChange={(v: any) => setCommission({ ...commission, status: v })}
            options={[
              { id: "PENDING", name: "معلقة فقط" },
              { id: "APPROVED", name: "معتمدة مع قيد" },
            ]}
          />

          <button
            onClick={saveCommission}
            className="rounded-2xl bg-[#0B2A4A] px-5 py-3 font-bold text-white"
          >
            حفظ العمولة
          </button>
        </div>

        <textarea
          className="mt-3 w-full rounded-2xl border bg-slate-50 p-4"
          placeholder="ملاحظات"
          value={commission.notes}
          onChange={(e) => setCommission({ ...commission, notes: e.target.value })}
        />

        <div className="mt-5 overflow-x-auto rounded-2xl border">
          <table className="min-w-[900px] w-full text-right">
            <thead className="bg-slate-100">
              <tr>
                <th className="p-3">التاريخ</th>
                <th className="p-3">المبلغ</th>
                <th className="p-3">الحالة</th>
                <th className="p-3">رقم القيد</th>
                <th className="p-3">رقم السند</th>
                <th className="p-3">ملاحظات</th>
                <th className="p-3">الإجراءات</th>
              </tr>
            </thead>

            <tbody>
              {(workerDetails?.commissions || []).length === 0 ? (
                <tr>
                  <td colSpan={7} className="p-5 text-center text-slate-500">
                    لا توجد عمولات
                  </td>
                </tr>
              ) : (
                (workerDetails?.commissions || []).map((r: any) => (
                  <tr key={r.id} className="border-t">
                    <td className="p-3">{r.commission_date || "-"}</td>
                    <td className="p-3 font-bold">{money(r.amount)}</td>
                    <td className="p-3">
                      <span className={`rounded-full px-3 py-1 text-xs font-bold ${
                        r.status === "PAID"
                          ? "bg-emerald-100 text-emerald-700"
                          : r.status === "APPROVED"
                          ? "bg-blue-100 text-blue-700"
                          : "bg-amber-100 text-amber-700"
                      }`}>
                        {commissionStatusLabel(r.status)}
                      </span>
                    </td>
                    <td className="p-3">{r.journal_entry_id || "-"}</td>
                    <td className="p-3">{r.voucher_id || "-"}</td>
                    <td className="p-3">{r.notes || "-"}</td>
                    <td className="p-3">
                      <div className="flex gap-2">
                        {r.status === "PENDING" && (
                          <button
                            onClick={() => approveCommission(r.id)}
                            className="rounded-xl bg-blue-700 px-3 py-2 text-sm font-bold text-white"
                          >
                            اعتماد
                          </button>
                        )}

                        {r.status === "APPROVED" && (
                          <button
                            onClick={() => payCommission(r.id)}
                            className="rounded-xl bg-emerald-600 px-3 py-2 text-sm font-bold text-white"
                          >
                            دفع
                          </button>
                        )}

                        {r.status === "PAID" && (
                          <span className="rounded-xl bg-slate-100 px-3 py-2 text-sm font-bold text-slate-600">
                            مكتملة
                          </span>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </>
    )}
  </TabBox>
)}

              {activeTab === "ATTENDANCE" && (
                <TabBox title="الحضور والانصراف">
                  {!selected ? <EmptyNeedSave /> : (
                    <>
                      <div className="grid grid-cols-1 gap-3 md:grid-cols-5">
                        <Input type="date" label="التاريخ" value={attendance.attendance_date} onChange={(v: any) => setAttendance({ ...attendance, attendance_date: v })} />
                        <Input type="time" label="دخول" value={attendance.check_in} onChange={(v: any) => setAttendance({ ...attendance, check_in: v })} />
                        <Input type="time" label="خروج" value={attendance.check_out} onChange={(v: any) => setAttendance({ ...attendance, check_out: v })} />
                        <Input type="number" label="ساعات العمل" value={attendance.work_hours} onChange={(v: any) => setAttendance({ ...attendance, work_hours: v })} />
                        <Input type="number" label="إضافي" value={attendance.overtime_hours} onChange={(v: any) => setAttendance({ ...attendance, overtime_hours: v })} />
                      </div>
                      <button onClick={saveAttendance} className="mt-3 rounded-2xl bg-[#0B2A4A] px-5 py-3 font-bold text-white">حفظ الحضور</button>
                      <MiniTable rows={workerDetails?.attendance || []} cols={["attendance_date", "check_in", "check_out", "work_hours", "overtime_hours", "status"]} />
                    </>
                  )}
                </TabBox>
              )}

              {activeTab === "SALARY" && (
                <TabBox title="الرواتب">
                  {!selected ? <EmptyNeedSave /> : (
                    <MiniTable rows={workerDetails?.salary_lines || []} cols={["salary_month", "basic_amount", "loan_deduction", "net_salary", "payment_status"]} />
                  )}
                </TabBox>
              )}

              {activeTab === "STATEMENT" && (
                <TabBox title="كشف الحساب">
                  {!selected ? <EmptyNeedSave /> : (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                      <Stat title="إجمالي السلف" value={money(workerDetails?.summary?.total_loans)} />
                      <Stat title="إجمالي العمولات" value={money(workerDetails?.summary?.total_commissions)} />
                      <Stat title="إجمالي الرواتب" value={money(workerDetails?.summary?.total_net_salary)} />
                    </div>
                  )}
                </TabBox>
              )}
            </div>
          </div>
        </div>
      )}
      {feedbackDialog}
    </section>
  );
}

function defaultWorker() {
  return {
    employee_no: "",
    worker_name: "",
    phone: "",
    email: "",
    job_title: "",
    department: "",
    salary_type: "MONTHLY",
    salary_rate: 0,
    hire_date: "",
    end_date: "",
    national_id: "",
    iqama_number: "",
    passport_number: "",
    nationality: "",
    birth_date: "",
    bank_name: "",
    iban: "",
    emergency_contact: "",
    emergency_phone: "",
    contract_type: "FULL_TIME",
    worker_status: "ACTIVE",
    photo: "",
    notes: "",
  };
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

function Stat({ title, value }: any) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white px-3 py-2.5">
      <div className="text-[10px] font-semibold text-slate-500">{title}</div>
      <div className="mt-1 text-xl font-black tabular-nums text-[#0B2A4A]">{value}</div>
    </div>
  );
}

function Input({ label, value, onChange, type = "text" }: any) {
  return (
    <label className="block">
      <div className="mb-1 text-sm font-bold text-slate-600">{label}</div>
      <input type={type} className="w-full rounded-2xl border bg-slate-50 p-3" value={value ?? ""} onChange={(e) => onChange(e.target.value)} />
    </label>
  );
}

function Select({ label, value, onChange, options }: any) {
  return (
    <label className="block">
      <div className="mb-1 text-sm font-bold text-slate-600">{label}</div>
      <select className="w-full rounded-2xl border bg-slate-50 p-3" value={value ?? ""} onChange={(e) => onChange(e.target.value)}>
        <option value="">اختر</option>
        {options.map((x: any) => <option key={x.id} value={x.id}>{x.name}</option>)}
      </select>
    </label>
  );
}

function TabBox({ title, children }: any) {
  return (
    <div className="rounded-3xl border bg-white p-5">
      <h3 className="mb-4 text-xl font-black text-[#0B2A4A]">{title}</h3>
      {children}
    </div>
  );
}

function EmptyNeedSave() {
  return <div className="rounded-2xl bg-amber-50 p-4 font-bold text-amber-700">احفظ العامل أولًا ثم أضف العمليات.</div>;
}

function MiniTable({ rows, cols }: any) {
  return (
    <div className="mt-5 overflow-x-auto rounded-2xl border">
      <table className="min-w-[700px] w-full text-right">
        <thead className="bg-slate-100">
          <tr>
            {cols.map((c: string) => <th key={c} className="p-3">{c}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr><td colSpan={cols.length} className="p-5 text-center text-slate-500">لا توجد بيانات</td></tr>
          ) : (
            rows.map((r: any, i: number) => (
              <tr key={i} className="border-t">
                {cols.map((c: string) => <td key={c} className="p-3">{r[c] ?? "-"}</td>)}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function salaryTypeLabel(v: any) {
  if (v === "HOURLY") return "بالساعة";
  if (v === "DAILY") return "باليوم";
  if (v === "WEEKLY") return "بالأسبوع";
  if (v === "MONTHLY") return "بالشهر";
  return v || "-";
}

function money(v: any) {
  return Number(v || 0).toFixed(3);
}
function commissionStatusLabel(v: any) {
  if (v === "PENDING") return "معلقة";
  if (v === "APPROVED") return "معتمدة";
  if (v === "PAID") return "مدفوعة";
  return v || "-";
}
