import { createHmac, timingSafeEqual } from "node:crypto";
import type { CommandEnvelope } from "@relatasoft/contracts";

export type SignableCommandFields = Omit<CommandEnvelope, "signature">;

function canonicalPayload(payload: Record<string, unknown>): string {
  return JSON.stringify(sortKeys(payload));
}

function sortKeys(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(sortKeys);
  }
  if (value !== null && typeof value === "object") {
    const obj = value as Record<string, unknown>;
    const sorted: Record<string, unknown> = {};
    for (const key of Object.keys(obj).sort()) {
      sorted[key] = sortKeys(obj[key]);
    }
    return sorted;
  }
  return value;
}

export function buildSigningPayload(fields: SignableCommandFields): string {
  return [
    fields.version,
    fields.commandId,
    fields.requestId,
    fields.type,
    fields.issuedAt,
    fields.expiresAt,
    fields.nonce,
    fields.idempotencyKey,
    canonicalPayload(fields.payload),
  ].join("\n");
}

export function signCommandFields(
  fields: SignableCommandFields,
  secret: Buffer,
): string {
  return createHmac("sha256", secret).update(buildSigningPayload(fields)).digest("base64url");
}

export function verifyCommandSignature(
  envelope: CommandEnvelope,
  secret: Buffer,
): boolean {
  const { signature, ...fields } = envelope;
  const expected = signCommandFields(fields, secret);
  const a = Buffer.from(signature);
  const b = Buffer.from(expected);
  if (a.length !== b.length) {
    return false;
  }
  return timingSafeEqual(a, b);
}
