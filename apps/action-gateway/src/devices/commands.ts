import type { CommandEnvelope, CommandType, Phase1CommandType } from "@relatasoft/contracts";
import {
  PHASE1_COMMAND_TYPES,
  AppError,
  ErrorCodes,
} from "@relatasoft/contracts";
import {
  generateNonce,
  generateUuidV4,
  signCommandFields,
} from "@relatasoft/crypto";

const PHASE1 = new Set<string>(PHASE1_COMMAND_TYPES);

export function assertAllowedCommand(type: CommandType): asserts type is Phase1CommandType {
  if (!PHASE1.has(type)) {
    throw new AppError(
      ErrorCodes.UNSUPPORTED_COMMAND,
      `Command ${type} is not available in phase 1`,
      403,
    );
  }
}

export function buildSignedCommand(input: {
  type: Phase1CommandType;
  requestId: string;
  payload: Record<string, unknown>;
  idempotencyKey: string;
  ttlSeconds: number;
  signingSecret: Buffer;
}): CommandEnvelope {
  assertAllowedCommand(input.type);
  const issuedAt = new Date();
  const expiresAt = new Date(issuedAt.getTime() + input.ttlSeconds * 1000);
  const fields = {
    version: "1.0" as const,
    commandId: generateUuidV4(),
    requestId: input.requestId,
    type: input.type,
    issuedAt: issuedAt.toISOString(),
    expiresAt: expiresAt.toISOString(),
    nonce: generateNonce(),
    idempotencyKey: input.idempotencyKey,
    payload: input.payload,
  };
  const signature = signCommandFields(fields, input.signingSecret);
  return { ...fields, signature };
}
