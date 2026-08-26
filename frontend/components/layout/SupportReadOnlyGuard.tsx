"use client";

import { useEffect, useRef, type ReactNode } from "react";

const READ_ONLY_MESSAGE = "وضع الدعم للقراءة فقط — لا يمكن تنفيذ تعديلات.";
const MUTATION_LABEL =
  /(?:حفظ|إضافة|إنشاء|جديد(?:ة)?|تعديل|حذف|تعطيل|تفعيل|ترحيل|اعتماد|صرف|دفع|تسوية|تحويل|تحديث|رفع|استيراد|إقفال|إغلاق الكرت|عكس المردود|إلغاء الفاتورة|تسجيل الوزن|استلام)/;

function isMutationControl(control: HTMLButtonElement | HTMLInputElement): boolean {
  if (control.dataset.supportReadonlyAllow === "true") return false;

  if (control instanceof HTMLInputElement) {
    return control.type === "submit" || control.type === "image";
  }

  const label = [
    control.textContent,
    control.getAttribute("aria-label"),
    control.getAttribute("title"),
  ]
    .filter(Boolean)
    .join(" ")
    .trim();

  return control.type === "submit" || label === "+" || MUTATION_LABEL.test(label);
}

export default function SupportReadOnlyGuard({
  active,
  children,
}: Readonly<{ active: boolean; children: ReactNode }>) {
  const container = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const root = container.current;

    if (!active || !root) return;

    const disableMutationControls = () => {
      root.querySelectorAll<HTMLButtonElement | HTMLInputElement>("button, input[type='submit'], input[type='image']")
        .forEach((control) => {
          if (!isMutationControl(control) || control.disabled) return;

          control.dataset.supportReadonlyDisabled = "true";
          control.disabled = true;
          control.setAttribute("aria-disabled", "true");
          control.setAttribute("title", READ_ONLY_MESSAGE);
        });
    };

    disableMutationControls();

    const observer = new MutationObserver(disableMutationControls);
    observer.observe(root, { childList: true, subtree: true });

    return () => {
      observer.disconnect();

      root.querySelectorAll<HTMLButtonElement | HTMLInputElement>("[data-support-readonly-disabled='true']")
        .forEach((control) => {
          control.disabled = false;
          control.removeAttribute("aria-disabled");
          control.removeAttribute("data-support-readonly-disabled");

          if (control.getAttribute("title") === READ_ONLY_MESSAGE) {
            control.removeAttribute("title");
          }
        });
    };
  }, [active]);

  return (
    <div
      ref={container}
      onSubmitCapture={(event) => {
        if (active) event.preventDefault();
      }}
    >
      {active ? (
        <div
          role="status"
          className="mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-900"
        >
          {READ_ONLY_MESSAGE}
        </div>
      ) : null}
      {children}
    </div>
  );
}
