"use client";

import { ReactNode } from "react";

interface Column {
  key: string;
  title: string;
  width?: string;
  render?: (row: any) => ReactNode;
}

interface Props {
  columns: Column[];
  rows: any[];
  emptyText?: string;
}

export default function ERPTable({
  columns,
  rows,
  emptyText = "لا توجد بيانات",
}: Props) {
  return (
    <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

      <div className="overflow-x-auto">

        <table className="min-w-full">

          <thead className="bg-slate-100">

            <tr>

              {columns.map((c) => (

                <th
                  key={c.key}
                  style={{ width: c.width }}
                  className="px-5 py-4 text-right text-sm font-black text-slate-700"
                >
                  {c.title}
                </th>

              ))}

            </tr>

          </thead>

          <tbody>

            {rows.length === 0 && (

              <tr>

                <td
                  colSpan={columns.length}
                  className="py-16 text-center text-slate-400"
                >
                  {emptyText}
                </td>

              </tr>

            )}

            {rows.map((row, index) => (

              <tr
                key={index}
                className="border-t hover:bg-slate-50 transition"
              >
                {columns.map((c) => (

                  <td
                    key={c.key}
                    className="px-5 py-4 text-sm"
                  >
                    {c.render ? c.render(row) : row[c.key]}
                  </td>

                ))}

              </tr>

            ))}

          </tbody>

        </table>

      </div>

    </div>
  );
}