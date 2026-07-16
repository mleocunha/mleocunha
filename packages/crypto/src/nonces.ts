import { createHmac, timingSafeEqual } from "node:crypto";

export function timingSafeEqualString(a: string, b: string): boolean {
  const ba = Buffer.from(a);
  const bb = Buffer.from(b);
  if (ba.length !== bb.length) {
    return false;
  }
  return timingSafeEqual(ba, bb);
}

export function hmacSha256Hex(secret: Buffer, message: string): string {
  return createHmac("sha256", secret).update(message).digest("hex");
}
