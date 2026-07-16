import type {
  AskReadonlyPayload,
  AskReadonlyResult,
  ChannelList,
  GetSessionPayload,
  ListSessionsPayload,
  OpenClawStatus,
  SessionInfo,
  SessionList,
} from "@relatasoft/contracts";

/**
 * Typed OpenClaw control surface.
 * Intentionally excludes arbitrary tool/shell/HTTP invocation.
 */
export interface OpenClawClient {
  getStatus(): Promise<OpenClawStatus>;
  listChannels(): Promise<ChannelList>;
  listSessions(payload?: ListSessionsPayload): Promise<SessionList>;
  getSession(payload: GetSessionPayload): Promise<SessionInfo>;
  askReadonly(payload: AskReadonlyPayload): Promise<AskReadonlyResult>;
}
