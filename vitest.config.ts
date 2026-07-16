import path from "node:path";
import { defineConfig } from "vitest/config";

export default defineConfig({
  resolve: {
    alias: {
      "@relatasoft/contracts": path.resolve(__dirname, "packages/contracts/src/index.ts"),
      "@relatasoft/crypto": path.resolve(__dirname, "packages/crypto/src/index.ts"),
      "@relatasoft/logging": path.resolve(__dirname, "packages/logging/src/index.ts"),
      "@relatasoft/openclaw-adapter": path.resolve(
        __dirname,
        "apps/openclaw-adapter/src/index.ts",
      ),
    },
  },
  test: {
    globals: false,
    environment: "node",
    include: [
      "packages/**/tests/**/*.test.ts",
      "apps/**/tests/**/*.test.ts",
      "tests/**/*.test.ts",
    ],
    coverage: {
      reporter: ["text", "html"],
    },
    testTimeout: 15000,
  },
});
