import type { NextConfig } from "next";
import path from "node:path";

const nextConfig: NextConfig = {
  // Separate local QA compiler output; never replace an already running developer build.
  ...(process.env.SULB_QA_UOM === "1" ? { distDir: ".next-qa-uom" } : {}),
  turbopack: {
    root: path.resolve(__dirname),
  },
};

export default nextConfig;
