"use client";

import { useCallback, useState } from "react";
import SystemDialog from "@/components/common/SystemDialog";

type FeedbackType = "success" | "error" | "warning" | "info";
type FeedbackAction = (value: string) => void | Promise<void>;

type FeedbackState = {
  open: boolean;
  type: FeedbackType | "confirm";
  title: string;
  message: string;
  action: FeedbackAction | null;
  inputLabel?: string;
  minLength?: number;
};

const initialState: FeedbackState = {
  open: false,
  type: "info",
  title: "",
  message: "",
  action: null,
};

export default function useSystemFeedback() {
  const [dialog, setDialog] = useState<FeedbackState>(initialState);
  const [value, setValue] = useState("");
  const [validationError, setValidationError] = useState("");
  const [busy, setBusy] = useState(false);

  const notify = useCallback((message: string, type: FeedbackType = "info") => {
    setDialog({
      open: true,
      type,
      title:
        type === "success"
          ? "تمت العملية بنجاح"
          : type === "error"
            ? "تعذر تنفيذ العملية"
            : type === "warning"
              ? "تحقق من البيانات"
              : "تنبيه",
      message,
      action: null,
    });
  }, []);

  const requestConfirmation = useCallback(
    (message: string, action: FeedbackAction, title = "تأكيد الإجراء") => {
      setValue("");
      setValidationError("");
      setDialog({ open: true, type: "confirm", title, message, action });
    },
    [],
  );

  const requestInput = useCallback(
    (
      message: string,
      action: FeedbackAction,
      options: { title?: string; inputLabel?: string; minLength?: number } = {},
    ) => {
      setValue("");
      setValidationError("");
      setDialog({
        open: true,
        type: "confirm",
        title: options.title || "تأكيد الإجراء",
        message,
        action,
        inputLabel: options.inputLabel || "السبب",
        minLength: options.minLength || 1,
      });
    },
    [],
  );

  async function submitDialog() {
    if (busy) return;
    if (!dialog.action) {
      setDialog((current) => ({ ...current, open: false }));
      return;
    }

    if (dialog.inputLabel && value.trim().length < (dialog.minLength || 1)) {
      setValidationError(
        (dialog.minLength || 1) > 1
          ? `يجب إدخال ${dialog.minLength} أحرف على الأقل.`
          : "هذا الحقل مطلوب.",
      );
      return;
    }

    const action = dialog.action;
    setBusy(true);

    try {
      await action(value);
      setDialog((current) =>
        current.action === action ? { ...current, open: false } : current,
      );
    } catch (error: any) {
      notify(error?.response?.data?.message || "تعذر تنفيذ العملية.", "error");
    } finally {
      setBusy(false);
    }
  }

  const feedbackDialog = (
    <SystemDialog
      open={dialog.open}
      type={dialog.type}
      title={dialog.title}
      message={dialog.message}
      loading={busy && dialog.type === "confirm"}
      showCancel={dialog.type === "confirm"}
      confirmText={dialog.type === "confirm" ? "تأكيد" : "حسنًا"}
      onClose={() => setDialog((current) => ({ ...current, open: false }))}
      onConfirm={submitDialog}
    >
      {dialog.inputLabel ? (
        <label className="block text-sm font-bold text-slate-700">
          {dialog.inputLabel} *
          <textarea
            rows={3}
            value={value}
            onChange={(event) => {
              setValue(event.target.value);
              setValidationError("");
            }}
            disabled={busy}
            className="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-[#0B2A4A]"
          />
          {validationError ? (
            <span role="alert" className="mt-2 block text-sm text-rose-600">
              {validationError}
            </span>
          ) : null}
        </label>
      ) : null}
    </SystemDialog>
  );

  return { notify, requestConfirmation, requestInput, feedbackDialog, busy };
}
