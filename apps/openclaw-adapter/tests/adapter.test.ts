import { describe, expect, it } from "vitest";
import {
  assertReadonlyPrompt,
  isForbiddenToolName,
  sanitizeForGpt,
  MockOpenClawClient,
} from "@relatasoft/openclaw-adapter";
import { AppError } from "@relatasoft/contracts";

describe("openclaw adapter policy", () => {
  it("blocks forbidden tool names", () => {
    expect(isForbiddenToolName("shell")).toBe(true);
    expect(isForbiddenToolName("getStatus")).toBe(false);
  });

  it("rejects mutating ask-readonly prompts", () => {
    expect(() => assertReadonlyPrompt("send this to João")).toThrow(AppError);
  });

  it("sanitizes secrets and phones", () => {
    const text = sanitizeForGpt(
      "token=abc123 call +5511999887766 Bearer sk-abcdefghijklmnopqrstuvwxyz",
    );
    expect(text).not.toContain("abc123");
    expect(text).toContain("••••");
    expect(text).toContain("[REDACTED]");
  });

  it("mock client returns qwen status", async () => {
    const client = new MockOpenClawClient();
    const status = await client.getStatus();
    expect(status.activeModel).toBe("qwen");
    expect(status.gatewayReachable).toBe(true);
  });
});
