import type { OpenClawClient } from "./client.js";
import { HttpOpenClawClient, type HttpOpenClawClientOptions } from "./operations/http-client.js";
import { MockOpenClawClient } from "./operations/mock-client.js";

export type OpenClawMode = "mock" | "http";

export type CreateOpenClawClientOptions =
  | { mode: "mock" }
  | ({ mode: "http" } & HttpOpenClawClientOptions);

export function createOpenClawClient(options: CreateOpenClawClientOptions): OpenClawClient {
  if (options.mode === "mock") {
    return new MockOpenClawClient();
  }
  return new HttpOpenClawClient(options);
}

export type { OpenClawClient } from "./client.js";
export { MockOpenClawClient } from "./operations/mock-client.js";
export { HttpOpenClawClient } from "./operations/http-client.js";
export {
  assertPhase1Command,
  assertReadonlyPrompt,
  isForbiddenToolName,
  FORBIDDEN_TOOLS,
} from "./policy.js";
export { sanitizeText, sanitizeForGpt, maskPhone, stripSensitivePaths } from "./sanitizers.js";
