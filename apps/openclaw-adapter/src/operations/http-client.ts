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
import { request } from "undici";
import { z } from "zod";
import type { OpenClawClient } from "../client.js";
import { assertReadonlyPrompt } from "../policy.js";
import { sanitizeForGpt } from "../sanitizers.js";

/**
 * HTTP client against a local OpenClaw gateway on loopback.
 * Paths are provisional and must be confirmed against the installed OpenClaw version.
 * The adapter never accepts arbitrary tool names or shell commands.
 */
export type HttpOpenClawClientOptions = {
  baseUrl: string;
  /** Optional local gateway token — never leaves the Mac. */
  gatewayToken?: string;
  fetchImpl?: typeof request;
};

const StatusResponseSchema = z.object({
  online: z.boolean().optional(),
  gatewayReachable: z.boolean().optional(),
  activeModel: z.string().optional(),
  model: z.string().optional(),
  timestamp: z.string().optional(),
});

const ChannelsResponseSchema = z.object({
  channels: z
    .array(
      z.object({
        id: z.string(),
        name: z.string().optional(),
        type: z.string().optional(),
        connected: z.boolean().optional(),
        status: z.string().optional(),
      }),
    )
    .optional(),
});

const SessionsResponseSchema = z.object({
  sessions: z
    .array(
      z.object({
        id: z.string(),
        channel: z.string().optional(),
        status: z.string().optional(),
        title: z.string().optional(),
        updatedAt: z.string().optional(),
      }),
    )
    .optional(),
  nextCursor: z.string().nullable().optional(),
});

export class HttpOpenClawClient implements OpenClawClient {
  private readonly baseUrl: string;
  private readonly gatewayToken: string | undefined;
  private readonly fetchImpl: typeof request;

  public constructor(options: HttpOpenClawClientOptions) {
    this.baseUrl = options.baseUrl.replace(/\/$/, "");
    this.gatewayToken = options.gatewayToken;
    this.fetchImpl = options.fetchImpl ?? request;
  }

  public async getStatus(): Promise<OpenClawStatus> {
    const raw = await this.getJson("/v1/status", StatusResponseSchema);
    return {
      online: raw.online ?? true,
      gatewayReachable: raw.gatewayReachable ?? true,
      activeModel: raw.activeModel ?? raw.model ?? "qwen",
      bridgeConnected: true,
      timestamp: raw.timestamp ?? new Date().toISOString(),
    };
  }

  public async listChannels(): Promise<ChannelList> {
    const raw = await this.getJson("/v1/channels", ChannelsResponseSchema);
    return {
      channels: (raw.channels ?? []).map((c) => ({
        id: c.id,
        name: c.name ?? c.id,
        type: c.type ?? "unknown",
        connected: c.connected ?? false,
        ...(c.status !== undefined ? { status: c.status } : {}),
      })),
    };
  }

  public async listSessions(payload?: ListSessionsPayload): Promise<SessionList> {
    const query = new URLSearchParams();
    if (payload?.limit !== undefined) query.set("limit", String(payload.limit));
    if (payload?.cursor !== undefined) query.set("cursor", payload.cursor);
    if (payload?.channel !== undefined) query.set("channel", payload.channel);
    if (payload?.status !== undefined) query.set("status", payload.status);
    const path = `/v1/sessions${query.size > 0 ? `?${query.toString()}` : ""}`;
    const raw = await this.getJson(path, SessionsResponseSchema);
    return {
      sessions: (raw.sessions ?? []).map((s) => ({
        id: s.id,
        channel: s.channel ?? "unknown",
        status: s.status ?? "unknown",
        ...(s.title !== undefined ? { title: s.title } : {}),
        ...(s.updatedAt !== undefined ? { updatedAt: s.updatedAt } : {}),
      })),
      nextCursor: raw.nextCursor ?? null,
    };
  }

  public async getSession(payload: GetSessionPayload): Promise<SessionInfo> {
    const raw = await this.getJson(
      `/v1/sessions/${encodeURIComponent(payload.sessionId)}`,
      z.object({
        id: z.string(),
        channel: z.string().optional(),
        status: z.string().optional(),
        title: z.string().optional(),
        updatedAt: z.string().optional(),
      }),
    );
    return {
      id: raw.id,
      channel: raw.channel ?? "unknown",
      status: raw.status ?? "unknown",
      ...(raw.title !== undefined ? { title: raw.title } : {}),
      ...(raw.updatedAt !== undefined ? { updatedAt: raw.updatedAt } : {}),
    };
  }

  public async askReadonly(payload: AskReadonlyPayload): Promise<AskReadonlyResult> {
    assertReadonlyPrompt(payload.prompt);
    const body = {
      prompt: payload.prompt,
      session_id: payload.sessionId ?? null,
      timeout_seconds: payload.timeoutSeconds ?? 60,
      mode: "readonly",
    };
    const raw = await this.postJson(
      "/v1/ask",
      body,
      z.object({
        answer: z.string(),
        model: z.string().optional(),
        sessionId: z.string().nullable().optional(),
        session_id: z.string().nullable().optional(),
        truncated: z.boolean().optional(),
      }),
    );
    return {
      answer: sanitizeForGpt(raw.answer),
      model: raw.model ?? "qwen",
      sessionId: raw.sessionId ?? raw.session_id ?? null,
      truncated: raw.truncated ?? false,
    };
  }

  private headers(): Record<string, string> {
    const headers: Record<string, string> = {
      Accept: "application/json",
      "Content-Type": "application/json",
    };
    if (this.gatewayToken !== undefined) {
      headers["Authorization"] = `Bearer ${this.gatewayToken}`;
    }
    return headers;
  }

  private async getJson<T>(path: string, schema: z.ZodType<T>): Promise<T> {
    try {
      const res = await this.fetchImpl(`${this.baseUrl}${path}`, {
        method: "GET",
        headers: this.headers(),
      });
      if (res.statusCode >= 500) {
        throw new AppError(ErrorCodes.OPENCLAW_OFFLINE, "OpenClaw gateway error", 503);
      }
      if (res.statusCode === 404) {
        throw new AppError(ErrorCodes.NOT_FOUND, "Resource not found on OpenClaw gateway", 404);
      }
      if (res.statusCode >= 400) {
        throw new AppError(ErrorCodes.INTERNAL_ERROR, "OpenClaw request failed", res.statusCode);
      }
      const json: unknown = await res.body.json();
      return schema.parse(json);
    } catch (error) {
      if (error instanceof AppError) throw error;
      throw new AppError(ErrorCodes.OPENCLAW_OFFLINE, "OpenClaw gateway unreachable", 503);
    }
  }

  private async postJson<T>(
    path: string,
    body: Record<string, unknown>,
    schema: z.ZodType<T>,
  ): Promise<T> {
    try {
      const res = await this.fetchImpl(`${this.baseUrl}${path}`, {
        method: "POST",
        headers: this.headers(),
        body: JSON.stringify(body),
      });
      if (res.statusCode >= 500) {
        throw new AppError(ErrorCodes.OPENCLAW_OFFLINE, "OpenClaw gateway error", 503);
      }
      if (res.statusCode >= 400) {
        throw new AppError(ErrorCodes.INTERNAL_ERROR, "OpenClaw request failed", res.statusCode);
      }
      const json: unknown = await res.body.json();
      return schema.parse(json);
    } catch (error) {
      if (error instanceof AppError) throw error;
      throw new AppError(ErrorCodes.OPENCLAW_OFFLINE, "OpenClaw gateway unreachable", 503);
    }
  }
}
