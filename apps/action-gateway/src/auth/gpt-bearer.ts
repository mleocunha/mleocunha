import { createHash, timingSafeEqual } from "node:crypto";
import type { FastifyReply, FastifyRequest } from "fastify";
import { AppError, ErrorCodes } from "@relatasoft/contracts";

export type AuthContext = {
  actor: string;
  tokenFingerprint: string;
};

function fingerprint(token: string): string {
  return createHash("sha256").update(token).digest("hex").slice(0, 12);
}

export function extractBearerToken(header: string | undefined): string | null {
  if (header === undefined || header.length === 0) {
    return null;
  }
  const match = /^Bearer\s+(.+)$/i.exec(header.trim());
  if (match === null || match[1] === undefined) {
    return null;
  }
  return match[1].trim();
}

export function verifyGptActionToken(token: string, expectedHashHex: string): boolean {
  const actual = createHash("sha256").update(token).digest("hex");
  const a = Buffer.from(actual, "hex");
  const b = Buffer.from(expectedHashHex, "hex");
  if (a.length !== b.length) {
    return false;
  }
  return timingSafeEqual(a, b);
}

export async function requireGptAuth(
  request: FastifyRequest,
  _reply: FastifyReply,
  expectedHashHex: string,
): Promise<AuthContext> {
  const token = extractBearerToken(request.headers.authorization);
  if (token === null || !verifyGptActionToken(token, expectedHashHex)) {
    throw new AppError(ErrorCodes.UNAUTHORIZED, "Invalid or missing bearer token", 401);
  }
  return {
    actor: "gpt_action",
    tokenFingerprint: fingerprint(token),
  };
}
