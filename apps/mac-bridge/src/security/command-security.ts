import {
  AppError,
  CommandEnvelopeSchema,
  ErrorCodes,
  Phase1CommandTypeSchema,
  type CommandEnvelope,
  type CommandResult,
} from "@relatasoft/contracts";
import { NonceCache, verifyCommandSignature } from "@relatasoft/crypto";
import type { OpenClawClient } from "@relatasoft/openclaw-adapter";
import {
  AskReadonlyPayloadSchema,
  GetSessionPayloadSchema,
  ListSessionsPayloadSchema,
} from "@relatasoft/contracts";
import { assertPhase1Command, assertReadonlyPrompt } from "@relatasoft/openclaw-adapter";

export class CommandSecurity {
  private readonly nonces = new NonceCache();
  private readonly idempotency = new Map<string, CommandResult>();

  public constructor(private readonly signingSecret: Buffer) {}

  public validate(raw: unknown): CommandEnvelope {
    const parsed = CommandEnvelopeSchema.safeParse(raw);
    if (!parsed.success) {
      throw new AppError(ErrorCodes.VALIDATION_ERROR, "Invalid command envelope", 400);
    }
    const envelope = parsed.data;
    if (Date.parse(envelope.expiresAt) < Date.now()) {
      throw new AppError(ErrorCodes.COMMAND_EXPIRED, "Command expired", 400);
    }
    if (!verifyCommandSignature(envelope, this.signingSecret)) {
      throw new AppError(ErrorCodes.INVALID_SIGNATURE, "Invalid command signature", 401);
    }
    if (!this.nonces.remember(envelope.nonce)) {
      throw new AppError(ErrorCodes.REPLAY_DETECTED, "Nonce already used", 409);
    }
    assertPhase1Command(envelope.type);
    Phase1CommandTypeSchema.parse(envelope.type);
    return envelope;
  }

  public getCached(idempotencyKey: string): CommandResult | undefined {
    return this.idempotency.get(idempotencyKey);
  }

  public cache(idempotencyKey: string, result: CommandResult): void {
    this.idempotency.set(idempotencyKey, result);
  }
}

export async function executeCommand(
  client: OpenClawClient,
  envelope: CommandEnvelope,
): Promise<unknown> {
  switch (envelope.type) {
    case "GET_STATUS":
      return client.getStatus();
    case "LIST_CHANNELS":
      return client.listChannels();
    case "LIST_SESSIONS": {
      const payload = ListSessionsPayloadSchema.parse(envelope.payload);
      return client.listSessions(payload);
    }
    case "GET_SESSION": {
      const payload = GetSessionPayloadSchema.parse(envelope.payload);
      return client.getSession(payload);
    }
    case "ASK_READONLY": {
      const payload = AskReadonlyPayloadSchema.parse(envelope.payload);
      assertReadonlyPrompt(payload.prompt);
      return client.askReadonly(payload);
    }
    default:
      throw new AppError(
        ErrorCodes.UNSUPPORTED_COMMAND,
        `Command ${envelope.type} not enabled`,
        403,
      );
  }
}
