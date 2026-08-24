"use client";

type Props = {
  disabled?: boolean;

  onView: () => void;
  onTransfer: () => void;
  onMaintenance: () => void;
  onSell: () => void;
  onDispose: () => void;
};

export default function ActionMenu({
  disabled = false,
  onView,
  onTransfer,
  onMaintenance,
  onSell,
  onDispose,
}: Props) {
  return (
    <div className="flex flex-wrap gap-2">
      <ActionButton
        label="التفاصيل"
        onClick={onView}
        disabled={disabled}
      />

      <ActionButton
        label="نقل الأصل"
        onClick={onTransfer}
        disabled={disabled}
      />

      <ActionButton
        label="صيانة"
        onClick={onMaintenance}
        disabled={disabled}
      />

      <ActionButton
        label="بيع"
        onClick={onSell}
        disabled={disabled}
      />

      <ActionButton
        label="شطب"
        onClick={onDispose}
        disabled={disabled}
        danger
      />
    </div>
  );
}

function ActionButton({
  label,
  onClick,
  disabled,
  danger = false,
}: {
  label: string;
  onClick: () => void;
  disabled: boolean;
  danger?: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={`inline-flex min-h-9 items-center justify-center rounded-xl px-3 text-xs font-black transition disabled:cursor-not-allowed disabled:opacity-50 ${
        danger
          ? "border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100"
          : "border border-slate-200 bg-white text-slate-700 hover:bg-slate-100"
      }`}
    >
      {label}
    </button>
  );
}