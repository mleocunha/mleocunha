import { z } from "zod";

export const DeviceAuthChallengeSchema = z
  .object({
    type: z.literal("auth_challenge"),
    challengeId: z.string().uuid(),
    nonce: z.string().min(16),
    issuedAt: z.string(),
    expiresAt: z.string(),
  })
  .strict();

export type DeviceAuthChallenge = z.infer<typeof DeviceAuthChallengeSchema>;

export const DeviceAuthResponseSchema = z
  .object({
    type: z.literal("auth_response"),
    deviceId: z.string().min(1).max(128),
    challengeId: z.string().uuid(),
    signature: z.string().min(1),
  })
  .strict();

export type DeviceAuthResponse = z.infer<typeof DeviceAuthResponseSchema>;

export const DeviceAuthResultSchema = z
  .object({
    type: z.literal("auth_result"),
    ok: z.boolean(),
    sessionId: z.string().optional(),
    errorCode: z.string().optional(),
    errorMessage: z.string().optional(),
  })
  .strict();

export type DeviceAuthResult = z.infer<typeof DeviceAuthResultSchema>;

export const DeviceWireMessageSchema = z.discriminatedUnion("type", [
  DeviceAuthChallengeSchema,
  DeviceAuthResponseSchema,
  DeviceAuthResultSchema,
  z
    .object({
      type: z.literal("command"),
      envelope: z.unknown(),
    })
    .strict(),
  z
    .object({
      type: z.literal("command_result"),
      result: z.unknown(),
    })
    .strict(),
  z
    .object({
      type: z.literal("ping"),
      at: z.string(),
    })
    .strict(),
  z
    .object({
      type: z.literal("pong"),
      at: z.string(),
    })
    .strict(),
]);

export type DeviceWireMessage = z.infer<typeof DeviceWireMessageSchema>;
