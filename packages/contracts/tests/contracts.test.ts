import { describe, expect, it } from "vitest";
import {
  CommandEnvelopeSchema,
  PHASE1_COMMAND_TYPES,
} from "@relatasoft/contracts";
import {
  generateNonce,
  generateUuidV4,
  NonceCache,
  signCommandFields,
  verifyCommandSignature,
  sha256Prefixed,
  hashToken,
  generateEd25519KeyPair,
  signEd25519,
  verifyEd25519,
} from "@relatasoft/crypto";

describe("contracts command envelope", () => {
  it("accepts a valid signed envelope shape", () => {
    const secret = Buffer.alloc(32, 7);
    const fields = {
      version: "1.0" as const,
      commandId: generateUuidV4(),
      requestId: generateUuidV4(),
      type: "GET_STATUS" as const,
      issuedAt: new Date().toISOString(),
      expiresAt: new Date(Date.now() + 60_000).toISOString(),
      nonce: generateNonce(),
      idempotencyKey: "idempotency-1",
      payload: {},
    };
    const signature = signCommandFields(fields, secret);
    const parsed = CommandEnvelopeSchema.parse({ ...fields, signature });
    expect(parsed.type).toBe("GET_STATUS");
    expect(verifyCommandSignature(parsed, secret)).toBe(true);
  });

  it("lists phase 1 read-only commands", () => {
    expect(PHASE1_COMMAND_TYPES).toContain("ASK_READONLY");
    expect(PHASE1_COMMAND_TYPES).not.toContain("SEND_PREPARED_MESSAGE");
  });
});

describe("crypto", () => {
  it("detects replayed nonces", () => {
    const cache = new NonceCache(60_000);
    expect(cache.remember("n1")).toBe(true);
    expect(cache.remember("n1")).toBe(false);
  });

  it("rejects tampered signatures", () => {
    const secret = Buffer.alloc(32, 3);
    const fields = {
      version: "1.0" as const,
      commandId: generateUuidV4(),
      requestId: generateUuidV4(),
      type: "LIST_CHANNELS" as const,
      issuedAt: new Date().toISOString(),
      expiresAt: new Date(Date.now() + 60_000).toISOString(),
      nonce: generateNonce(),
      idempotencyKey: "idempotency-2",
      payload: { x: 1 },
    };
    const signature = signCommandFields(fields, secret);
    const envelope = { ...fields, payload: { x: 2 }, signature };
    expect(verifyCommandSignature(envelope, secret)).toBe(false);
  });

  it("hashes tokens and payloads", () => {
    expect(hashToken("abc")).toHaveLength(64);
    expect(sha256Prefixed("hi")).toMatch(/^sha256:[a-f0-9]{64}$/);
  });

  it("signs and verifies Ed25519 device challenges", () => {
    const keys = generateEd25519KeyPair();
    const message = "challenge:nonce:device";
    const sig = signEd25519(message, keys.privateKeyBase64);
    expect(verifyEd25519(message, sig, keys.publicKeyBase64)).toBe(true);
    expect(verifyEd25519("other", sig, keys.publicKeyBase64)).toBe(false);
  });
});
