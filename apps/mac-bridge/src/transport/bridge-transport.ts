import WebSocket from "ws";
import {
  AppError,
  DeviceAuthChallengeSchema,
  DeviceAuthResultSchema,
  ErrorCodes,
  type CommandResult,
} from "@relatasoft/contracts";
import { signEd25519 } from "@relatasoft/crypto";
import type { Logger } from "@relatasoft/logging";
import type { OpenClawClient } from "@relatasoft/openclaw-adapter";
import type { BridgeConfig } from "../config.js";
import type { LocalAuditLog } from "../audit/local-audit.js";
import { CommandSecurity, executeCommand } from "../security/command-security.js";

export class BridgeTransport {
  private socket: WebSocket | null = null;
  private reconnectAttempt = 0;
  private stopped = false;
  private readonly security: CommandSecurity;

  public constructor(
    private readonly config: BridgeConfig,
    private readonly client: OpenClawClient,
    private readonly audit: LocalAuditLog,
    private readonly logger: Logger,
  ) {
    this.security = new CommandSecurity(config.commandSigningSecret);
  }

  public start(): void {
    this.stopped = false;
    this.connect();
  }

  public stop(): void {
    this.stopped = true;
    this.socket?.close();
    this.socket = null;
  }

  private connect(): void {
    if (this.stopped) return;
    this.logger.info({ url: this.config.GATEWAY_WS_URL }, "Connecting to Action Gateway");
    const socket = new WebSocket(this.config.GATEWAY_WS_URL);
    this.socket = socket;

    socket.on("open", () => {
      this.logger.info("WebSocket open; awaiting auth challenge");
    });

    socket.on("message", (data) => {
      void this.onMessage(typeof data === "string" ? data : data.toString("utf8"));
    });

    socket.on("close", () => {
      this.logger.warn("WebSocket closed");
      this.scheduleReconnect();
    });

    socket.on("error", (err) => {
      this.logger.error({ err }, "WebSocket error");
    });
  }

  private scheduleReconnect(): void {
    if (this.stopped) return;
    const delay = Math.min(
      this.config.RECONNECT_MIN_MS * 2 ** this.reconnectAttempt,
      this.config.RECONNECT_MAX_MS,
    );
    this.reconnectAttempt += 1;
    this.logger.info({ delay }, "Scheduling reconnect");
    setTimeout(() => this.connect(), delay);
  }

  private async onMessage(raw: string): Promise<void> {
    let parsed: unknown;
    try {
      parsed = JSON.parse(raw) as unknown;
    } catch {
      this.logger.warn("Received non-JSON message");
      return;
    }

    if (
      typeof parsed === "object" &&
      parsed !== null &&
      "type" in parsed &&
      (parsed as { type: unknown }).type === "auth_challenge"
    ) {
      this.handleChallenge(parsed);
      return;
    }

    if (
      typeof parsed === "object" &&
      parsed !== null &&
      "type" in parsed &&
      (parsed as { type: unknown }).type === "auth_result"
    ) {
      const result = DeviceAuthResultSchema.parse(parsed);
      if (result.ok) {
        this.reconnectAttempt = 0;
        this.logger.info({ sessionId: result.sessionId }, "Device authenticated");
      } else {
        this.logger.error(
          { code: result.errorCode, message: result.errorMessage },
          "Device auth failed",
        );
      }
      return;
    }

    if (
      typeof parsed === "object" &&
      parsed !== null &&
      "type" in parsed &&
      (parsed as { type: unknown }).type === "command"
    ) {
      const message = parsed as unknown as { envelope: unknown };
      await this.handleCommand(message.envelope);
    }
  }

  private handleChallenge(parsed: unknown): void {
    const challenge = DeviceAuthChallengeSchema.parse(parsed);
    const message = `${challenge.challengeId}:${challenge.nonce}:${this.config.BRIDGE_DEVICE_ID}`;
    const signature = signEd25519(message, this.config.devicePrivateKey);
    this.socket?.send(
      JSON.stringify({
        type: "auth_response",
        deviceId: this.config.BRIDGE_DEVICE_ID,
        challengeId: challenge.challengeId,
        signature,
      }),
    );
  }

  private async handleCommand(rawEnvelope: unknown): Promise<void> {
    const started = Date.now();
    let commandId = "unknown";
    let requestId = "unknown";
    try {
      const envelope = this.security.validate(rawEnvelope);
      commandId = envelope.commandId;
      requestId = envelope.requestId;

      const cached = this.security.getCached(envelope.idempotencyKey);
      if (cached !== undefined) {
        this.socket?.send(JSON.stringify({ type: "command_result", result: cached }));
        return;
      }

      this.audit.append({
        actor: this.config.BRIDGE_DEVICE_ID,
        source: "mac_bridge",
        requestId,
        operation: envelope.type,
        outcome: "accepted",
        riskLevel: 0,
      });

      const data = await executeCommand(this.client, envelope);
      const result: CommandResult = {
        commandId,
        requestId,
        ok: true,
        data,
        latencyMs: Date.now() - started,
      };
      this.security.cache(envelope.idempotencyKey, result);
      this.audit.append({
        actor: this.config.BRIDGE_DEVICE_ID,
        source: "mac_bridge",
        requestId,
        operation: envelope.type,
        outcome: "executed",
        riskLevel: 0,
        details: { latencyMs: result.latencyMs },
      });
      this.socket?.send(JSON.stringify({ type: "command_result", result }));
    } catch (error) {
      const appErr =
        error instanceof AppError
          ? error
          : new AppError(ErrorCodes.INTERNAL_ERROR, "Bridge handler error", 500);
      const result: CommandResult = {
        commandId,
        requestId,
        ok: false,
        errorCode: appErr.code,
        errorMessage: appErr.message,
        latencyMs: Date.now() - started,
      };
      this.audit.append({
        actor: this.config.BRIDGE_DEVICE_ID,
        source: "mac_bridge",
        requestId,
        operation: "command",
        outcome: "failed",
        riskLevel: 0,
        details: { code: appErr.code },
      });
      this.socket?.send(JSON.stringify({ type: "command_result", result }));
    }
  }
}
