import { z } from "zod";

export const OpenClawStatusSchema = z
  .object({
    online: z.boolean(),
    gatewayReachable: z.boolean(),
    activeModel: z.string(),
    bridgeConnected: z.boolean(),
    timestamp: z.string(),
  })
  .strict();

export type OpenClawStatus = z.infer<typeof OpenClawStatusSchema>;

export const ChannelInfoSchema = z
  .object({
    id: z.string(),
    name: z.string(),
    type: z.string(),
    connected: z.boolean(),
    status: z.string().optional(),
  })
  .strict();

export type ChannelInfo = z.infer<typeof ChannelInfoSchema>;

export const ChannelListSchema = z
  .object({
    channels: z.array(ChannelInfoSchema),
  })
  .strict();

export type ChannelList = z.infer<typeof ChannelListSchema>;

export const SessionInfoSchema = z
  .object({
    id: z.string(),
    channel: z.string(),
    status: z.string(),
    title: z.string().optional(),
    updatedAt: z.string().optional(),
  })
  .strict();

export type SessionInfo = z.infer<typeof SessionInfoSchema>;

export const SessionListSchema = z
  .object({
    sessions: z.array(SessionInfoSchema),
    nextCursor: z.string().nullable().optional(),
  })
  .strict();

export type SessionList = z.infer<typeof SessionListSchema>;

export const AskReadonlyResultSchema = z
  .object({
    answer: z.string(),
    model: z.string(),
    sessionId: z.string().nullable().optional(),
    truncated: z.boolean().optional(),
  })
  .strict();

export type AskReadonlyResult = z.infer<typeof AskReadonlyResultSchema>;

export const HealthResponseSchema = z
  .object({
    status: z.literal("ok"),
    service: z.literal("relatasoft-openclaw-action-gateway"),
    version: z.string(),
  })
  .strict();

export type HealthResponse = z.infer<typeof HealthResponseSchema>;

export const ApiErrorSchema = z
  .object({
    error: z
      .object({
        code: z.string(),
        message: z.string(),
        requestId: z.string().optional(),
        auditId: z.string().optional(),
      })
      .strict(),
  })
  .strict();

export type ApiError = z.infer<typeof ApiErrorSchema>;
