import type { FastifyInstance, FastifyReply, FastifyRequest } from "fastify";
import { z } from "zod";
import {
  AppError,
  AskReadonlyPayloadSchema,
  ErrorCodes,
  ListSessionsPayloadSchema,
  type CommandResult,
} from "@relatasoft/contracts";
import { generateUuidV4, sha256Prefixed } from "@relatasoft/crypto";
import type { GatewayConfig } from "../config.js";
import type { AuditStore } from "../audit/store.js";
import { requireGptAuth } from "../auth/gpt-bearer.js";
import { buildSignedCommand } from "../devices/commands.js";
import type { DeviceHub } from "../transport/device-hub.js";
import { riskForCommand } from "../policy/risk.js";
import type { IdempotencyStore } from "../db/idempotency.js";

const AskBodySchema = z
  .object({
    prompt: z.string().min(1).max(12000),
    session_id: z.string().min(1).max(256).nullable().optional(),
    timeout_seconds: z.number().int().min(1).max(120).optional(),
  })
  .strict();

const SessionsQuerySchema = z
  .object({
    limit: z.coerce.number().int().min(1).max(100).optional(),
    cursor: z.string().max(256).optional(),
    channel: z.string().max(64).optional(),
    status: z.string().max(64).optional(),
  })
  .strict();

function clientMeta(request: FastifyRequest): Record<string, unknown> {
  return {
    ip: request.ip,
    userAgent: request.headers["user-agent"] ?? null,
  };
}

function unwrapResult(result: CommandResult): unknown {
  if (!result.ok) {
    throw new AppError(
      result.errorCode ?? ErrorCodes.INTERNAL_ERROR,
      result.errorMessage ?? "Command failed on Mac Bridge",
      502,
    );
  }
  return result.data;
}

export function registerRoutes(
  app: FastifyInstance,
  deps: {
    config: GatewayConfig;
    hub: DeviceHub;
    audit: AuditStore;
    idempotency: IdempotencyStore;
  },
): void {
  const { config, hub, audit, idempotency } = deps;

  app.get("/health", async () => ({
    status: "ok" as const,
    service: "relatasoft-openclaw-action-gateway" as const,
    version: config.serviceVersion,
  }));

  app.addHook("preHandler", async (request, reply) => {
    if (request.url === "/health" || request.url.startsWith("/v1/device/")) {
      return;
    }
    if (!request.url.startsWith("/v1/")) {
      return;
    }
    const auth = await requireGptAuth(request, reply, config.GPT_ACTION_TOKEN_HASH);
    (request as FastifyRequest & { auth: typeof auth }).auth = auth;
  });

  async function dispatch(
    request: FastifyRequest,
    reply: FastifyReply,
    operation: string,
    type: "GET_STATUS" | "LIST_CHANNELS" | "LIST_SESSIONS" | "GET_SESSION" | "ASK_READONLY",
    payload: Record<string, unknown>,
  ): Promise<unknown> {
    const requestId =
      typeof request.headers["x-request-id"] === "string"
        ? request.headers["x-request-id"]
        : generateUuidV4();
    const idempotencyKey =
      typeof request.headers["idempotency-key"] === "string"
        ? request.headers["idempotency-key"]
        : `${operation}:${requestId}`;

    const payloadHash = sha256Prefixed(JSON.stringify(payload));
    const existing = idempotency.get(idempotencyKey);
    if (existing !== undefined) {
      if (existing.requestHash !== payloadHash) {
        audit.append({
          actor: "gpt_action",
          source: "gpt_action",
          requestId,
          operation,
          outcome: "rejected",
          riskLevel: riskForCommand(type),
          payloadHash,
          details: { reason: "idempotency_conflict", ...clientMeta(request) },
        });
        throw new AppError(
          ErrorCodes.IDEMPOTENCY_CONFLICT,
          "Idempotency-Key reused with a different payload",
          409,
        );
      }
      reply.code(existing.responseStatus);
      return existing.responseBody;
    }

    const accepted = audit.append({
      actor: "gpt_action",
      source: "gpt_action",
      requestId,
      operation,
      outcome: "accepted",
      riskLevel: riskForCommand(type),
      payloadHash,
      details: clientMeta(request),
    });

    try {
      const envelope = buildSignedCommand({
        type,
        requestId,
        payload,
        idempotencyKey,
        ttlSeconds: config.COMMAND_TTL_SECONDS,
        signingSecret: config.commandSigningSecret,
      });
      const result = await hub.sendCommand(envelope);
      const data = unwrapResult(result);
      const body = {
        ...(typeof data === "object" && data !== null ? data : { data }),
        auditId: accepted.auditId,
        requestId,
      };
      audit.append({
        actor: "gpt_action",
        source: "gateway",
        requestId,
        operation,
        outcome: "executed",
        riskLevel: riskForCommand(type),
        payloadHash,
        details: { latencyMs: result.latencyMs, commandId: envelope.commandId },
      });
      idempotency.set({
        key: idempotencyKey,
        requestHash: payloadHash,
        responseStatus: 200,
        responseBody: body,
        createdAt: Date.now(),
      });
      return body;
    } catch (error) {
      const appErr =
        error instanceof AppError
          ? error
          : new AppError(ErrorCodes.INTERNAL_ERROR, "Unexpected error", 500);
      audit.append({
        actor: "gpt_action",
        source: "gateway",
        requestId,
        operation,
        outcome: "failed",
        riskLevel: riskForCommand(type),
        payloadHash,
        details: { code: appErr.code, ...clientMeta(request) },
      });
      throw appErr;
    }
  }

  app.get("/v1/openclaw/status", async (request, reply) => {
    return dispatch(request, reply, "getOpenClawStatus", "GET_STATUS", {});
  });

  app.get("/v1/openclaw/channels", async (request, reply) => {
    return dispatch(request, reply, "listOpenClawChannels", "LIST_CHANNELS", {});
  });

  app.get("/v1/openclaw/sessions", async (request, reply) => {
    const query = SessionsQuerySchema.parse(request.query);
    const payload = ListSessionsPayloadSchema.parse({
      ...(query.limit !== undefined ? { limit: query.limit } : {}),
      ...(query.cursor !== undefined ? { cursor: query.cursor } : {}),
      ...(query.channel !== undefined ? { channel: query.channel } : {}),
      ...(query.status !== undefined ? { status: query.status } : {}),
    });
    return dispatch(
      request,
      reply,
      "listOpenClawSessions",
      "LIST_SESSIONS",
      payload as Record<string, unknown>,
    );
  });

  app.post("/v1/openclaw/ask-readonly", async (request, reply) => {
    const body = AskBodySchema.parse(request.body);
    const payload = AskReadonlyPayloadSchema.parse({
      prompt: body.prompt,
      sessionId: body.session_id ?? null,
      ...(body.timeout_seconds !== undefined
        ? { timeoutSeconds: body.timeout_seconds }
        : {}),
    });
    return dispatch(
      request,
      reply,
      "askOpenClawReadonly",
      "ASK_READONLY",
      payload as Record<string, unknown>,
    );
  });
}
