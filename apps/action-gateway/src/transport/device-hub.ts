import type { WebSocket } from "ws";
import {
  AppError,
  DeviceAuthChallengeSchema,
  DeviceAuthResponseSchema,
  ErrorCodes,
  type CommandEnvelope,
  type CommandResult,
  type DeviceAuthResult,
} from "@relatasoft/contracts";
import {
  generateNonce,
  generateUuidV4,
  verifyEd25519,
} from "@relatasoft/crypto";
export type DeviceHubLogger = {
  info: (obj: unknown, msg?: string) => void;
  warn: (obj: unknown, msg?: string) => void;
  error: (obj: unknown, msg?: string) => void;
};

export type ConnectedDevice = {
  deviceId: string;
  sessionId: string;
  socket: WebSocket;
  authenticatedAt: number;
};

type PendingCommand = {
  resolve: (result: CommandResult) => void;
  reject: (error: Error) => void;
  timer: ReturnType<typeof setTimeout>;
};

export class DeviceHub {
  private device: ConnectedDevice | null = null;
  private readonly pending = new Map<string, PendingCommand>();
  private readonly pendingChallenges = new Map<
    string,
    { deviceId: string; nonce: string; expiresAt: number }
  >();

  public constructor(
    private readonly options: {
      expectedDeviceId: string;
      devicePublicKey: string;
      logger: DeviceHubLogger;
      commandTimeoutMs: number;
    },
  ) {}

  public isConnected(): boolean {
    return this.device !== null && this.device.socket.readyState === 1;
  }

  public getDeviceId(): string | null {
    return this.device?.deviceId ?? null;
  }

  public beginAuth(socket: WebSocket): void {
    const challengeId = generateUuidV4();
    const nonce = generateNonce();
    const issuedAt = new Date();
    const expiresAt = new Date(issuedAt.getTime() + 60_000);
    this.pendingChallenges.set(challengeId, {
      deviceId: this.options.expectedDeviceId,
      nonce,
      expiresAt: expiresAt.getTime(),
    });

    const challenge = DeviceAuthChallengeSchema.parse({
      type: "auth_challenge",
      challengeId,
      nonce,
      issuedAt: issuedAt.toISOString(),
      expiresAt: expiresAt.toISOString(),
    });
    socket.send(JSON.stringify(challenge));
  }

  public handleMessage(socket: WebSocket, raw: string): void {
    let parsed: unknown;
    try {
      parsed = JSON.parse(raw) as unknown;
    } catch {
      this.sendAuthResult(socket, {
        type: "auth_result",
        ok: false,
        errorCode: ErrorCodes.VALIDATION_ERROR,
        errorMessage: "Invalid JSON",
      });
      socket.close();
      return;
    }

    if (
      typeof parsed === "object" &&
      parsed !== null &&
      "type" in parsed &&
      (parsed as { type: unknown }).type === "auth_response"
    ) {
      this.handleAuthResponse(socket, parsed);
      return;
    }

    if (
      typeof parsed === "object" &&
      parsed !== null &&
      "type" in parsed &&
      (parsed as { type: unknown }).type === "command_result"
    ) {
      const message = parsed as unknown as { result: CommandResult };
      this.resolveCommand(message.result);
      return;
    }

    if (
      typeof parsed === "object" &&
      parsed !== null &&
      "type" in parsed &&
      (parsed as { type: unknown }).type === "pong"
    ) {
      return;
    }

    this.options.logger.warn({ msg: "Unexpected device message type" });
  }

  public handleDisconnect(socket: WebSocket): void {
    if (this.device?.socket === socket) {
      this.options.logger.info({ deviceId: this.device.deviceId }, "Device disconnected");
      this.device = null;
    }
  }

  public async sendCommand(envelope: CommandEnvelope): Promise<CommandResult> {
    if (!this.isConnected() || this.device === null) {
      throw new AppError(ErrorCodes.BRIDGE_OFFLINE, "Mac Bridge is not connected", 503);
    }
    const socket = this.device.socket;
    return await new Promise<CommandResult>((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(envelope.commandId);
        reject(new AppError(ErrorCodes.TIMEOUT, "Command timed out waiting for Mac Bridge", 504));
      }, this.options.commandTimeoutMs);
      this.pending.set(envelope.commandId, { resolve, reject, timer });
      socket.send(JSON.stringify({ type: "command", envelope }));
    });
  }

  private handleAuthResponse(socket: WebSocket, parsed: unknown): void {
    const result = DeviceAuthResponseSchema.safeParse(parsed);
    if (!result.success) {
      this.sendAuthResult(socket, {
        type: "auth_result",
        ok: false,
        errorCode: ErrorCodes.DEVICE_AUTH_FAILED,
        errorMessage: "Invalid auth response",
      });
      socket.close();
      return;
    }
    const body = result.data;
    const challenge = this.pendingChallenges.get(body.challengeId);
    this.pendingChallenges.delete(body.challengeId);

    if (challenge === undefined || challenge.expiresAt < Date.now()) {
      this.sendAuthResult(socket, {
        type: "auth_result",
        ok: false,
        errorCode: ErrorCodes.DEVICE_AUTH_FAILED,
        errorMessage: "Challenge expired or unknown",
      });
      socket.close();
      return;
    }

    if (body.deviceId !== this.options.expectedDeviceId) {
      this.sendAuthResult(socket, {
        type: "auth_result",
        ok: false,
        errorCode: ErrorCodes.DEVICE_AUTH_FAILED,
        errorMessage: "Unknown device",
      });
      socket.close();
      return;
    }

    const message = `${body.challengeId}:${challenge.nonce}:${body.deviceId}`;
    const ok = verifyEd25519(message, body.signature, this.options.devicePublicKey);
    if (!ok) {
      this.sendAuthResult(socket, {
        type: "auth_result",
        ok: false,
        errorCode: ErrorCodes.DEVICE_AUTH_FAILED,
        errorMessage: "Invalid device signature",
      });
      socket.close();
      return;
    }

    if (this.device !== null && this.device.socket !== socket) {
      try {
        this.device.socket.close();
      } catch {
        // ignore
      }
    }

    const sessionId = generateUuidV4();
    this.device = {
      deviceId: body.deviceId,
      sessionId,
      socket,
      authenticatedAt: Date.now(),
    };
    this.sendAuthResult(socket, {
      type: "auth_result",
      ok: true,
      sessionId,
    });
    this.options.logger.info({ deviceId: body.deviceId, sessionId }, "Device authenticated");
  }

  private resolveCommand(result: CommandResult): void {
    const pending = this.pending.get(result.commandId);
    if (pending === undefined) {
      return;
    }
    clearTimeout(pending.timer);
    this.pending.delete(result.commandId);
    pending.resolve(result);
  }

  private sendAuthResult(socket: WebSocket, result: DeviceAuthResult): void {
    socket.send(JSON.stringify(result));
  }
}
