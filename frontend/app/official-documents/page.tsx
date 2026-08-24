"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import api from "../api";

type OfficialDoc = {
  id: number;
  doc_title: string;
  doc_type: string;
  doc_content: string;
  status: string;
  created_at?: string;
  branch_name?: string;
  created_by_name?: string;
  attachments_count?: number;
};

type FloatingItem = {
  id: string;
  type: "image" | "stamp" | "signature";
  src: string;
  x: number;
  y: number;
  w: number;
  h: number;
};

export default function OfficialDocumentsPage() {
  const editorRef = useRef<HTMLDivElement>(null);
  const paperRef = useRef<HTMLDivElement>(null);

  const [docs, setDocs] = useState<OfficialDoc[]>([]);
  const [settings, setSettings] = useState<any>(null);
  const [selected, setSelected] = useState<OfficialDoc | null>(null);
  const [showEditor, setShowEditor] = useState(false);
  const [loading, setLoading] = useState(false);

  const [attachments, setAttachments] = useState<File[]>([]);
  const [savedAttachments, setSavedAttachments] = useState<any[]>([]);
  const [floatingItems, setFloatingItems] = useState<FloatingItem[]>([]);
  const [activeDrag, setActiveDrag] = useState<string | null>(null);
  const [search, setSearch] = useState("");

  const [form, setForm] = useState({
    doc_title: "",
    doc_type: "GENERAL",
    status: "DRAFT",
  });

  const fileUrl = (path?: string) => {
    if (!path) return "";
    return `http://127.0.0.1:8000/storage/${path}`;
  };

  const loadDocs = async () => {
    const res = await api.get("/official-documents");
    setDocs(res.data.data || []);
  };

  const loadSettings = async () => {
    const res = await api.get("/company-settings");
    setSettings(res.data.data || {});
  };

  useEffect(() => {
    loadDocs();
    loadSettings();
  }, []);

  const filteredDocs = useMemo(() => {
    return docs.filter((d) =>
      `${d.doc_title} ${d.doc_type} ${d.status}`
        .toLowerCase()
        .includes(search.toLowerCase())
    );
  }, [docs, search]);

  const exec = (cmd: string, value?: string) => {
    document.execCommand(cmd, false, value);
  };

  const openNew = () => {
    setSelected(null);
    setForm({ doc_title: "", doc_type: "GENERAL", status: "DRAFT" });
    setAttachments([]);
    setSavedAttachments([]);
    setFloatingItems([]);
    setShowEditor(true);

    setTimeout(() => {
      if (editorRef.current) editorRef.current.innerHTML = "";
    }, 100);
  };

  const openEdit = async (id: number) => {
    const res = await api.get(`/official-documents/${id}`);
    const doc = res.data.data.document;
    const set = res.data.data.settings;

    setSelected(doc);
    setSettings(set || settings);
    setSavedAttachments(res.data.data.attachments || []);
    setAttachments([]);

    setForm({
      doc_title: doc.doc_title || "",
      doc_type: doc.doc_type || "GENERAL",
      status: doc.status || "DRAFT",
    });

    setShowEditor(true);

    setTimeout(() => {
      if (editorRef.current) editorRef.current.innerHTML = doc.doc_content || "";
      loadFloatingFromContent(doc.doc_content || "");
    }, 100);
  };

  const loadFloatingFromContent = (html: string) => {
    const temp = document.createElement("div");
    temp.innerHTML = html;
    const raw = temp.querySelector("#floating-data")?.textContent;

    if (raw) {
      try {
        setFloatingItems(JSON.parse(raw));
      } catch {
        setFloatingItems([]);
      }
    } else {
      setFloatingItems([]);
    }

    temp.querySelector("#floating-data")?.remove();
    if (editorRef.current) editorRef.current.innerHTML = temp.innerHTML;
  };

  const getContentWithFloating = () => {
    const content = editorRef.current?.innerHTML || "";
    return `${content}<script id="floating-data" type="application/json">${JSON.stringify(
      floatingItems
    )}</script>`;
  };

  const saveDoc = async () => {
    if (!form.doc_title.trim()) return alert("اكتب عنوان الورقة");

    setLoading(true);

    try {
      const payload = {
        ...form,
        doc_content: getContentWithFloating(),
      };

      let docId = selected?.id;

      if (selected) {
        await api.put(`/official-documents/${selected.id}`, payload);
      } else {
        const res = await api.post("/official-documents", payload);
        docId = res.data.id;
      }

      if (docId && attachments.length > 0) {
        const fd = new FormData();
        attachments.forEach((file) => fd.append("files[]", file));

        await api.post(`/official-documents/${docId}/attachments`, fd, {
          headers: { "Content-Type": "multipart/form-data" },
        });
      }

      alert("تم حفظ الورقة");
      setShowEditor(false);
      loadDocs();
    } catch (e: any) {
      alert(e?.response?.data?.message || "حدث خطأ أثناء الحفظ");
    } finally {
      setLoading(false);
    }
  };

  const deleteDoc = async (id: number) => {
    if (!confirm("هل تريد حذف هذه الورقة؟")) return;
    await api.delete(`/official-documents/${id}`);
    alert("تم حذف الورقة");
    loadDocs();
  };

  const addFloatingImage = (file: File) => {
    const reader = new FileReader();
    reader.onload = () => {
      setFloatingItems((old) => [
        ...old,
        {
          id: `${Date.now()}-${Math.random()}`,
          type: "image",
          src: String(reader.result),
          x: 120,
          y: 240,
          w: 180,
          h: 120,
        },
      ]);
    };
    reader.readAsDataURL(file);
  };

  const addStampOrSignature = (type: "stamp" | "signature") => {
    const src =
      type === "stamp"
        ? fileUrl(settings?.stamp_path)
        : fileUrl(settings?.signature_path);

    if (!src) return alert(type === "stamp" ? "ارفع الختم من الإعدادات أولًا" : "ارفع التوقيع من الإعدادات أولًا");

    setFloatingItems((old) => [
      ...old,
      {
        id: `${Date.now()}-${type}`,
        type,
        src,
        x: type === "stamp" ? 360 : 160,
        y: 760,
        w: type === "stamp" ? 140 : 180,
        h: 90,
      },
    ]);
  };

  const onDragMove = (e: React.MouseEvent<HTMLDivElement>) => {
    if (!activeDrag || !paperRef.current) return;

    const rect = paperRef.current.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    setFloatingItems((items) =>
      items.map((item) =>
        item.id === activeDrag
          ? { ...item, x: Math.max(0, x - item.w / 2), y: Math.max(0, y - item.h / 2) }
          : item
      )
    );
  };

  const resizeItem = (id: string, diff: number) => {
    setFloatingItems((items) =>
      items.map((item) =>
        item.id === id
          ? {
              ...item,
              w: Math.max(50, item.w + diff),
              h: Math.max(35, item.h + diff * 0.6),
            }
          : item
      )
    );
  };

  const removeFloating = (id: string) => {
    setFloatingItems((items) => items.filter((x) => x.id !== id));
  };

  const cleanContentForPrint = () => {
    const temp = document.createElement("div");
    temp.innerHTML = editorRef.current?.innerHTML || selected?.doc_content || "";
    temp.querySelector("#floating-data")?.remove();
    return temp.innerHTML;
  };

  const buildOfficialPaperHtml = () => {
    const floatingHtml = floatingItems
      .map(
        (item) => `
          <img src="${item.src}" style="
            position:absolute;
            left:${item.x}px;
            top:${item.y}px;
            width:${item.w}px;
            height:${item.h}px;
            object-fit:contain;
            z-index:20;
            ${item.type === "stamp" ? "opacity:.88;" : ""}
          " />
        `
      )
      .join("");

    return `
      <html lang="ar" dir="rtl">
        <head>
          <title>${form.doc_title || "ورقة رسمية"}</title>
          <style>
            @page { size: A4; margin: 0; }
            * { box-sizing: border-box; }
            body { margin: 0; background: white; font-family: Arial, Tahoma, sans-serif; color: #111827; }
            .paper { width: 210mm; min-height: 297mm; background: white; margin: 0 auto; padding: 18mm 18mm 22mm; position: relative; overflow:hidden; }
            .header { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid ${settings?.primary_color || "#0B2A4A"}; padding-bottom: 14px; }
            .company h1 { margin: 0 0 8px; color: ${settings?.primary_color || "#0B2A4A"}; font-size: 24px; }
            .company div { font-size: 13px; line-height: 1.8; color: #475569; }
            .logo { max-width: 140px; max-height: 90px; object-fit: contain; }
            .doc-title { text-align: center; margin: 30px 0 20px; font-size: 22px; color: ${settings?.primary_color || "#0B2A4A"}; }
            .content { min-height: 650px; line-height: 2; font-size: 16px; position:relative; z-index:2; }
            .footer { position: absolute; left: 18mm; right: 18mm; bottom: 12mm; border-top: 1px solid #cbd5e1; padding-top: 10px; font-size: 12px; color: #64748b; text-align: center; }
          </style>
        </head>
        <body>
          <div class="paper">
            ${floatingHtml}
            <div class="header">
              <div class="company">
                <h1>${settings?.print_company_name || "اسم المكتب"}</h1>
                <div>${settings?.print_address || ""}</div>
                <div>${settings?.print_phone || ""} - ${settings?.print_email || ""}</div>
                <div>السجل التجاري: ${settings?.commercial_register || "-"} | الرقم الضريبي: ${settings?.tax_number || "-"}</div>
              </div>
              ${settings?.logo_path ? `<img class="logo" src="${fileUrl(settings.logo_path)}" />` : ""}
            </div>
            <h2 class="doc-title">${form.doc_title || "ورقة رسمية"}</h2>
            <div class="content">${cleanContentForPrint()}</div>
            <div class="footer">${settings?.report_footer || ""}</div>
          </div>
        </body>
      </html>
    `;
  };

  const printDoc = () => {
    const win = window.open("", "_blank");
    if (!win) return;
    win.document.write(buildOfficialPaperHtml());
    win.document.close();
    setTimeout(() => win.print(), 500);
  };

  return (
    <section dir="rtl" className="space-y-5">
      <div className="rounded-3xl bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-6 text-white shadow-lg">
        <p className="text-sm text-blue-100">الأوراق الرسمية والمراسلات</p>
        <h1 className="mt-2 text-3xl font-black">إدارة المستندات الرسمية</h1>
        <p className="mt-2 text-sm text-blue-100">
          محرر مرن مع صور قابلة للسحب، ختم وتوقيع بأي مكان، مرفقات وطباعة رسمية.
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
        <Stat title="إجمالي الأوراق" value={docs.length} />
        <Stat title="مسودات" value={docs.filter((d) => d.status === "DRAFT").length} />
        <Stat title="معتمدة" value={docs.filter((d) => d.status === "APPROVED").length} />
        <button onClick={openNew} className="rounded-3xl bg-[#0B2A4A] p-5 text-xl font-black text-white shadow-sm">
          + إنشاء ورقة
        </button>
      </div>

      <div className="rounded-3xl border bg-white p-4 shadow-sm">
        <input
          className="w-full rounded-2xl border bg-slate-50 p-4 outline-none focus:border-[#0B2A4A]"
          placeholder="بحث بعنوان الورقة أو النوع أو الحالة..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      <div className="overflow-hidden rounded-3xl border bg-white shadow-sm">
        <div className="border-b p-4">
          <h2 className="text-xl font-black text-[#0B2A4A]">الأوراق المحفوظة</h2>
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-[1100px] w-full text-right">
            <thead className="bg-slate-100 text-slate-700">
              <tr>
                <th className="p-4">العنوان</th>
                <th className="p-4">النوع</th>
                <th className="p-4">الحالة</th>
                <th className="p-4">المرفقات</th>
                <th className="p-4">الفرع</th>
                <th className="p-4">أنشئت بواسطة</th>
                <th className="p-4">التاريخ</th>
                <th className="p-4">الإجراءات</th>
              </tr>
            </thead>

            <tbody>
              {filteredDocs.length === 0 ? (
                <tr>
                  <td colSpan={8} className="p-6 text-center text-slate-500">لا توجد أوراق</td>
                </tr>
              ) : (
                filteredDocs.map((doc) => (
                  <tr key={doc.id} className="border-t hover:bg-slate-50">
                    <td className="p-4 font-bold text-[#0B2A4A]">{doc.doc_title}</td>
                    <td className="p-4">{doc.doc_type}</td>
                    <td className="p-4">
                      <span className="rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-700">
                        {doc.status}
                      </span>
                    </td>
                    <td className="p-4">{doc.attachments_count || 0}</td>
                    <td className="p-4">{doc.branch_name || "-"}</td>
                    <td className="p-4">{doc.created_by_name || "-"}</td>
                    <td className="p-4">{doc.created_at || "-"}</td>
                    <td className="p-4">
                      <div className="flex flex-wrap gap-2">
                        <button onClick={() => openEdit(doc.id)} className="rounded-xl bg-blue-700 px-4 py-2 text-sm font-bold text-white">فتح</button>
                        <button onClick={async () => { await openEdit(doc.id); setTimeout(printDoc, 600); }} className="rounded-xl bg-slate-700 px-4 py-2 text-sm font-bold text-white">طباعة</button>
                        <button onClick={() => deleteDoc(doc.id)} className="rounded-xl bg-rose-600 px-4 py-2 text-sm font-bold text-white">حذف</button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {showEditor && (
        <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
          <div className="mx-auto max-w-7xl rounded-3xl bg-white shadow-2xl">
            <div className="sticky top-0 z-10 rounded-t-3xl border-b bg-white p-4">
              <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                  <h2 className="text-2xl font-black text-[#0B2A4A]">
                    {selected ? "تعديل ورقة رسمية" : "إنشاء ورقة رسمية"}
                  </h2>
                  <p className="text-sm text-slate-500">
                    اسحب الصور والختم والتوقيع داخل الورقة وحدد مكانها بدقة.
                  </p>
                </div>

                <div className="flex flex-wrap gap-2">
                  <button onClick={saveDoc} disabled={loading} className="rounded-2xl bg-[#0B2A4A] px-5 py-3 font-bold text-white disabled:opacity-60">
                    {loading ? "جاري الحفظ..." : "حفظ"}
                  </button>
                  <button onClick={printDoc} className="rounded-2xl bg-emerald-600 px-5 py-3 font-bold text-white">طباعة رسمية</button>
                  <button onClick={() => setShowEditor(false)} className="rounded-2xl bg-slate-200 px-5 py-3 font-bold text-slate-700">إغلاق</button>
                </div>
              </div>

              <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                <input className="rounded-2xl border bg-slate-50 p-4" placeholder="عنوان الورقة" value={form.doc_title} onChange={(e) => setForm({ ...form, doc_title: e.target.value })} />
                <select className="rounded-2xl border bg-slate-50 p-4" value={form.doc_type} onChange={(e) => setForm({ ...form, doc_type: e.target.value })}>
                  <option value="GENERAL">عام</option>
                  <option value="ROAD_PAPER">ورقة طريق</option>
                  <option value="LETTER">خطاب</option>
                  <option value="AGREEMENT">اتفاق</option>
                  <option value="NOTICE">إشعار</option>
                </select>
                <select className="rounded-2xl border bg-slate-50 p-4" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                  <option value="DRAFT">مسودة</option>
                  <option value="APPROVED">معتمدة</option>
                  <option value="ARCHIVED">مؤرشفة</option>
                </select>
              </div>

              <Toolbar exec={exec} />
            </div>

            <div className="grid grid-cols-1 gap-4 p-4 xl:grid-cols-[1fr_370px]">
              <div className="rounded-3xl bg-slate-200 p-5">
                <div
                  ref={paperRef}
                  onMouseMove={onDragMove}
                  onMouseUp={() => setActiveDrag(null)}
                  onMouseLeave={() => setActiveDrag(null)}
                  className="relative mx-auto min-h-[1120px] max-w-[850px] overflow-hidden bg-white p-10 shadow-xl"
                >
                  {floatingItems.map((item) => (
                    <div
                      key={item.id}
                      onMouseDown={() => setActiveDrag(item.id)}
                      className="absolute cursor-move rounded-lg border border-blue-400 bg-white/10"
                      style={{ left: item.x, top: item.y, width: item.w, height: item.h, zIndex: 30 }}
                    >
                      <img src={item.src} className="h-full w-full object-contain" draggable={false} />
                      <div className="absolute -top-8 left-0 flex gap-1">
                        <button onClick={(e) => { e.stopPropagation(); resizeItem(item.id, 15); }} className="rounded bg-blue-700 px-2 py-1 text-xs text-white">+</button>
                        <button onClick={(e) => { e.stopPropagation(); resizeItem(item.id, -15); }} className="rounded bg-slate-700 px-2 py-1 text-xs text-white">-</button>
                        <button onClick={(e) => { e.stopPropagation(); removeFloating(item.id); }} className="rounded bg-rose-600 px-2 py-1 text-xs text-white">حذف</button>
                      </div>
                    </div>
                  ))}

                  <div
                    ref={editorRef}
                    contentEditable
                    suppressContentEditableWarning
                    className="relative z-10 min-h-[980px] leading-9 outline-none"
                    style={{ fontSize: "17px" }}
                  />
                </div>
              </div>

              <div className="space-y-4">
                <Panel title="عناصر داخل الورقة">
                  <div className="grid grid-cols-1 gap-2">
                    <button onClick={() => addStampOrSignature("stamp")} className="rounded-2xl bg-slate-100 px-4 py-3 font-bold">إضافة الختم وتحريكه</button>
                    <button onClick={() => addStampOrSignature("signature")} className="rounded-2xl bg-slate-100 px-4 py-3 font-bold">إضافة التوقيع وتحريكه</button>
                    <label className="cursor-pointer rounded-2xl bg-slate-100 px-4 py-3 text-center font-bold">
                      إضافة صورة داخل الورقة
                      <input type="file" accept="image/*" className="hidden" onChange={(e) => {
                        const file = e.target.files?.[0];
                        if (file) addFloatingImage(file);
                      }} />
                    </label>
                  </div>
                </Panel>

                <Panel title="المرفقات">
                  <input type="file" multiple className="w-full rounded-2xl border bg-slate-50 p-4" onChange={(e) => setAttachments(Array.from(e.target.files || []))} />

                  <div className="mt-4 space-y-2">
                    {savedAttachments.map((att) => (
                      <div key={att.id} className="flex items-center justify-between rounded-2xl bg-slate-50 p-3 text-sm">
                        <a href={att.url} target="_blank" className="font-bold text-[#0B2A4A]">{att.original_name}</a>
                        <button
                          onClick={async () => {
                            await api.delete(`/official-documents/attachments/${att.id}`);
                            setSavedAttachments(savedAttachments.filter((x) => x.id !== att.id));
                          }}
                          className="rounded-xl bg-rose-600 px-3 py-1 font-bold text-white"
                        >
                          حذف
                        </button>
                      </div>
                    ))}

                    {attachments.map((file, index) => (
                      <div key={index} className="rounded-2xl bg-amber-50 p-3 text-sm font-bold text-amber-700">
                        جاهز للرفع: {file.name}
                      </div>
                    ))}
                  </div>
                </Panel>

                <Panel title="قوالب سريعة">
                  <div className="grid grid-cols-1 gap-2">
                    <TemplateButton editorRef={editorRef} title="قالب ورقة طريق" html={`
                      <h2 style="text-align:center;">ورقة طريق</h2>
                      <p><b>التاريخ:</b> ${new Date().toLocaleDateString("ar-SA")}</p>
                      <p><b>السائق:</b> ................................</p>
                      <p><b>السيارة:</b> ................................</p>
                      <p><b>الوجهة:</b> ................................</p>
                      <p><b>الملاحظات:</b></p>
                      <p>................................................................</p>
                    `} />
                    <TemplateButton editorRef={editorRef} title="قالب خطاب رسمي" html={`
                      <h2 style="text-align:center;">خطاب رسمي</h2>
                      <p>السادة / ................................ المحترمين</p>
                      <p>السلام عليكم ورحمة الله وبركاته،</p>
                      <p>نفيدكم بأنه ....................................................</p>
                      <p>وتقبلوا خالص التحية والتقدير.</p>
                    `} />
                  </div>
                </Panel>
              </div>
            </div>
          </div>
        </div>
      )}
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

function Panel({ title, children }: any) {
  return (
    <div className="rounded-3xl border bg-white p-5 shadow-sm">
      <h3 className="mb-3 text-lg font-black text-[#0B2A4A]">{title}</h3>
      {children}
    </div>
  );
}

function TemplateButton({ editorRef, title, html }: any) {
  return (
    <button
      onClick={() => {
        if (editorRef.current) editorRef.current.innerHTML += html;
      }}
      className="rounded-2xl bg-slate-100 px-4 py-3 font-bold text-slate-700"
    >
      {title}
    </button>
  );
}

function Toolbar({ exec }: { exec: (cmd: string, value?: string) => void }) {
  return (
    <div className="mt-4 flex flex-wrap gap-2 rounded-2xl bg-slate-100 p-3">
      <button onClick={() => exec("bold")} className="tool-btn">غامق</button>
      <button onClick={() => exec("italic")} className="tool-btn">مائل</button>
      <button onClick={() => exec("underline")} className="tool-btn">تحته خط</button>
      <button onClick={() => exec("justifyRight")} className="tool-btn">يمين</button>
      <button onClick={() => exec("justifyCenter")} className="tool-btn">وسط</button>
      <button onClick={() => exec("justifyLeft")} className="tool-btn">يسار</button>
      <button onClick={() => exec("insertUnorderedList")} className="tool-btn">نقاط</button>
      <button onClick={() => exec("insertOrderedList")} className="tool-btn">ترقيم</button>
      <button onClick={() => exec("fontSize", "4")} className="tool-btn">حجم +</button>
      <button onClick={() => exec("foreColor", "#0B2A4A")} className="tool-btn">كحلي</button>

      <style jsx>{`
        .tool-btn {
          border-radius: 12px;
          background: white;
          padding: 8px 12px;
          font-weight: 700;
          color: #0f172a;
          border: 1px solid #e2e8f0;
        }
        .tool-btn:hover {
          background: #0b2a4a;
          color: white;
        }
      `}</style>
    </div>
  );
}