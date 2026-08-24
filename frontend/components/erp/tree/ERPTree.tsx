"use client";

import { useMemo, useState } from "react";

type TreeNode = {
  id: any;
  parent_id?: any;
  label?: string;
  code?: string;
  type?: string;
  is_group?: any;
  children?: TreeNode[];
  raw?: any;
};

export default function ERPTree({
  rows = [],
  idKey = "id",
  parentKey = "parent_id",
  labelKey = "account_name",
  codeKey = "account_code",
  typeKey = "account_type",
  onSelect,
}: any) {
  const [open, setOpen] = useState<Record<string, boolean>>({});

  const tree = useMemo(() => {
    const map = new Map<any, TreeNode>();
    const roots: TreeNode[] = [];

    rows.forEach((r: any) => {
      map.set(r[idKey], {
        id: r[idKey],
        parent_id: r[parentKey],
        label: r[labelKey],
        code: r[codeKey],
        type: r[typeKey],
        is_group: r.is_group,
        children: [],
        raw: r,
      });
    });

    map.forEach((node) => {
      if (node.parent_id && map.has(node.parent_id)) {
        map.get(node.parent_id)?.children?.push(node);
      } else {
        roots.push(node);
      }
    });

    const sortTree = (nodes: TreeNode[]) => {
      nodes.sort((a, b) => String(a.code || "").localeCompare(String(b.code || "")));
      nodes.forEach((n) => sortTree(n.children || []));
    };

    sortTree(roots);
    return roots;
  }, [rows, idKey, parentKey, labelKey, codeKey, typeKey]);

  function toggle(id: any) {
    setOpen((prev) => ({ ...prev, [id]: !prev[id] }));
  }

  function renderNode(node: TreeNode, level = 0) {
    const hasChildren = (node.children || []).length > 0;
    const isOpen = open[node.id] ?? true;

    return (
      <div key={node.id}>
        <div
          className="flex cursor-pointer items-center gap-2 rounded-2xl px-3 py-2 hover:bg-slate-50"
          style={{ paddingRight: 12 + level * 24 }}
          onClick={() => onSelect?.(node.raw)}
        >
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              if (hasChildren) toggle(node.id);
            }}
            className="h-7 w-7 rounded-xl bg-slate-100 text-sm font-black text-slate-700"
          >
            {hasChildren ? (isOpen ? "−" : "+") : "•"}
          </button>

          <span className="font-black text-[#0B2A4A]">{node.code}</span>

          <span className="font-bold text-slate-800">{node.label}</span>

          <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">
            {node.is_group == 1 ? "تجميعي" : "تحليلي"}
          </span>

          {node.type && (
            <span className="rounded-full bg-blue-50 px-2 py-1 text-xs font-bold text-blue-700">
              {node.type}
            </span>
          )}
        </div>

        {hasChildren && isOpen && (
          <div className="mt-1 space-y-1">
            {node.children?.map((child) => renderNode(child, level + 1))}
          </div>
        )}
      </div>
    );
  }

  if (!tree.length) {
    return (
      <div className="rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center font-bold text-slate-500">
        لا توجد بيانات في الشجرة
      </div>
    );
  }

  return (
    <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="space-y-1">
        {tree.map((node) => renderNode(node))}
      </div>
    </div>
  );
}