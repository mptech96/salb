"use client";

import { ReactNode } from "react";

interface ERPCardProps {
  title?: string;
  subtitle?: string;
  actions?: ReactNode;
  children: ReactNode;
}

export default function ERPCard({
  title,
  subtitle,
  actions,
  children,
}: ERPCardProps) {
  return (
    <div className="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      {(title || actions) && (
        <div className="flex flex-col gap-3 border-b border-slate-100 bg-slate-50 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            {title && (
              <h2 className="text-lg font-black text-slate-800">
                {title}
              </h2>
            )}

            {subtitle && (
              <p className="mt-1 text-sm text-slate-500">
                {subtitle}
              </p>
            )}
          </div>

          {actions && (
            <div className="flex flex-wrap gap-2">
              {actions}
            </div>
          )}
        </div>
      )}

      <div className="p-6">
        {children}
      </div>
    </div>
  );
}