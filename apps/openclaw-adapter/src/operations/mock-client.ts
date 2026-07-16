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
import { AppError, ErrorCodes } from "@relatasoft/contracts";
import type { OpenClawClient } from "../client.js";
import { assertReadonlyPrompt } from "../policy.js";
import { sanitizeForGpt } from "../sanitizers.js";

export class MockOpenClawClient implements OpenClawClient {
  private online = true;
  private readonly sessions: SessionInfo[] = [
    {
      id: "sess_demo_1",
      channel: "whatsapp",
      status: "active",
      title: "Demo session",
      updatedAt: new Date().toISOString(),
    },
  ];

  public setOnline(online: boolean): void {
    this.online = online;
  }

  public async getStatus(): Promise<OpenClawStatus> {
    this.ensureOnline();
    return {
      online: true,
      gatewayReachable: true,
      activeModel: "qwen",
      bridgeConnected: true,
      timestamp: new Date().toISOString(),
    };
  }

  public async listChannels(): Promise<ChannelList> {
    this.ensureOnline();
    return {
      channels: [
        {
          id: "whatsapp",
          name: "WhatsApp",
          type: "messaging",
          connected: true,
          status: "ready",
        },
        {
          id: "local",
          name: "Local",
          type: "local",
          connected: true,
          status: "ready",
        },
      ],
    };
  }

  public async listSessions(payload?: ListSessionsPayload): Promise<SessionList> {
    this.ensureOnline();
    let sessions = [...this.sessions];
    if (payload?.channel !== undefined) {
      sessions = sessions.filter((s) => s.channel === payload.channel);
    }
    if (payload?.status !== undefined) {
      sessions = sessions.filter((s) => s.status === payload.status);
    }
    const limit = payload?.limit ?? 50;
    return {
      sessions: sessions.slice(0, limit),
      nextCursor: null,
    };
  }

  public async getSession(payload: GetSessionPayload): Promise<SessionInfo> {
    this.ensureOnline();
    const found = this.sessions.find((s) => s.id === payload.sessionId);
    if (!found) {
      throw new AppError(ErrorCodes.NOT_FOUND, "Session not found", 404);
    }
    return found;
  }

  public async askReadonly(payload: AskReadonlyPayload): Promise<AskReadonlyResult> {
    this.ensureOnline();
    assertReadonlyPrompt(payload.prompt);
    const answer = sanitizeForGpt(
      `Readonly analysis (mock/qwen): ${payload.prompt.slice(0, 500)}`,
    );
    return {
      answer,
      model: "qwen",
      sessionId: payload.sessionId ?? null,
      truncated: false,
    };
  }

  private ensureOnline(): void {
    if (!this.online) {
      throw new AppError(ErrorCodes.OPENCLAW_OFFLINE, "OpenClaw gateway unreachable", 503);
    }
  }
}
