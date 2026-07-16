import Fastify, { type FastifyError } from "fastify";
import helmet from "@fastify/helmet";
import rateLimit from "@fastify/rate-limit";
import websocket from "@fastify/websocket";
import { AppError, ErrorCodes } from "@relatasoft/contracts";
import { ZodError } from "zod";
import type { GatewayConfig } from "./config.js";
import { AuditStore } from "./audit/store.js";
import { IdempotencyStore } from "./db/idempotency.js";
import { DeviceHub } from "./transport/device-hub.js";
import { registerRoutes } from "./routes/openclaw.js";

export type BuiltGateway = Awaited<ReturnType<typeof buildApp>>;

export async function buildApp(config: GatewayConfig) {
  const app = Fastify({
    logger: {
      level: config.LOG_LEVEL,
      name: "action-gateway",
      redact: {
        paths: ["req.headers.authorization", "headers.authorization"],
        censor: "[REDACTED]",
      },
    },
    bodyLimit: config.MAX_BODY_BYTES,
    requestIdHeader: "x-request-id",
    genReqId: () => crypto.randomUUID(),
  });

  await app.register(helmet, {
    global: true,
    contentSecurityPolicy: false,
  });

  await app.register(rateLimit, {
    max: config.RATE_LIMIT_MAX,
    timeWindow: config.RATE_LIMIT_WINDOW_MS,
  });

  await app.register(websocket);

  const audit = new AuditStore();
  const idempotency = new IdempotencyStore();
  const hub = new DeviceHub({
    expectedDeviceId: config.DEVICE_ID,
    devicePublicKey: config.DEVICE_PUBLIC_KEY,
    logger: app.log,
    commandTimeoutMs: config.COMMAND_TIMEOUT_MS,
  });

  app.get("/v1/device/connect", { websocket: true }, (socket) => {
    hub.beginAuth(socket);
    socket.on("message", (data) => {
      const raw = typeof data === "string" ? data : data.toString("utf8");
      hub.handleMessage(socket, raw);
    });
    socket.on("close", () => {
      hub.handleDisconnect(socket);
    });
  });

  registerRoutes(app, { config, hub, audit, idempotency });

  app.setErrorHandler((error: FastifyError | Error, request, reply) => {
    if (error instanceof ZodError) {
      void reply.code(400).send({
        error: {
          code: ErrorCodes.VALIDATION_ERROR,
          message: "Request validation failed",
          requestId: request.id,
        },
      });
      return;
    }
    if (error instanceof AppError) {
      void reply.code(error.statusCode).send({
        error: {
          code: error.code,
          message: error.message,
          requestId: request.id,
        },
      });
      return;
    }
    const statusCode =
      "statusCode" in error && typeof error.statusCode === "number"
        ? error.statusCode
        : 500;
    request.log.error({ err: error }, "Unhandled error");
    void reply.code(statusCode).send({
      error: {
        code: ErrorCodes.INTERNAL_ERROR,
        message: statusCode === 500 ? "Internal server error" : error.message,
        requestId: request.id,
      },
    });
  });

  return { app, hub, audit, config };
}
