import {
  createHash,
  generateKeyPairSync,
  randomBytes,
  randomUUID,
  sign,
  timingSafeEqual,
  verify,
} from "node:crypto";

export function sha256Hex(input: string | Buffer): string {
  return createHash("sha256").update(input).digest("hex");
}

export function sha256Prefixed(input: string | Buffer): string {
  return `sha256:${sha256Hex(input)}`;
}

export function generateNonce(bytes = 24): string {
  return randomBytes(bytes).toString("base64url");
}

export function generateUuidV4(): string {
  return randomUUID();
}

export function hashToken(token: string): string {
  return sha256Hex(token);
}

export function timingSafeEqualHex(a: string, b: string): boolean {
  const ba = Buffer.from(a, "hex");
  const bb = Buffer.from(b, "hex");
  if (ba.length !== bb.length || ba.length === 0) {
    return false;
  }
  return timingSafeEqual(ba, bb);
}

export type Ed25519KeyPair = {
  publicKeyBase64: string;
  privateKeyBase64: string;
};

export function generateEd25519KeyPair(): Ed25519KeyPair {
  const { publicKey, privateKey } = generateKeyPairSync("ed25519");
  return {
    publicKeyBase64: publicKey.export({ type: "spki", format: "der" }).toString("base64"),
    privateKeyBase64: privateKey.export({ type: "pkcs8", format: "der" }).toString("base64"),
  };
}

export function signEd25519(message: string, privateKeyBase64: string): string {
  const key = Buffer.from(privateKeyBase64, "base64");
  const signature = sign(null, Buffer.from(message, "utf8"), {
    key,
    format: "der",
    type: "pkcs8",
  });
  return signature.toString("base64url");
}

export function verifyEd25519(
  message: string,
  signatureBase64Url: string,
  publicKeyBase64: string,
): boolean {
  try {
    const key = Buffer.from(publicKeyBase64, "base64");
    const signature = Buffer.from(signatureBase64Url, "base64url");
    return verify(
      null,
      Buffer.from(message, "utf8"),
      { key, format: "der", type: "spki" },
      signature,
    );
  } catch {
    return false;
  }
}

export class NonceCache {
  private readonly seen = new Map<string, number>();
  private readonly ttlMs: number;
  private readonly maxEntries: number;

  public constructor(ttlMs = 10 * 60 * 1000, maxEntries = 10_000) {
    this.ttlMs = ttlMs;
    this.maxEntries = maxEntries;
  }

  public has(nonce: string): boolean {
    this.evictExpired();
    return this.seen.has(nonce);
  }

  public remember(nonce: string, now = Date.now()): boolean {
    this.evictExpired(now);
    if (this.seen.has(nonce)) {
      return false;
    }
    if (this.seen.size >= this.maxEntries) {
      const first = this.seen.keys().next().value;
      if (first !== undefined) {
        this.seen.delete(first);
      }
    }
    this.seen.set(nonce, now + this.ttlMs);
    return true;
  }

  private evictExpired(now = Date.now()): void {
    for (const [nonce, expiresAt] of this.seen) {
      if (expiresAt <= now) {
        this.seen.delete(nonce);
      }
    }
  }
}
