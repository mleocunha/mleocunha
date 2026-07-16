import { z } from "zod";

export const COMMAND_VERSION = "1.0" as const;

export const CommandTypeSchema = z.enum([
  "GET_STATUS",
  "LIST_CHANNELS",
  "LIST_SESSIONS",
  "GET_SESSION",
  "ASK_READONLY",
  "PREPARE_MESSAGE",
  "SEND_PREPARED_MESSAGE",
  "RUN_NAMED_WORKFLOW",
  "GET_WORKFLOW_RESULT",
  "CANCEL_OPERATION",
]);

export type CommandType = z.infer<typeof CommandTypeSchema>;

/** Phase 1 allows only read-only command types. */
export const PHASE1_COMMAND_TYPES = [
  "GET_STATUS",
  "LIST_CHANNELS",
  "LIST_SESSIONS",
  "GET_SESSION",
  "ASK_READONLY",
] as const satisfies ReadonlyArray<CommandType>;

export type Phase1CommandType = (typeof PHASE1_COMMAND_TYPES)[number];

export const Phase1CommandTypeSchema = z.enum(PHASE1_COMMAND_TYPES);

export const UuidV4Schema = z.string().uuid();

export const IsoDateTimeSchema = z.string().datetime({ offset: true });

export const CommandEnvelopeSchema = z
  .object({
    version: z.literal(COMMAND_VERSION),
    commandId: UuidV4Schema,
    requestId: UuidV4Schema,
    type: CommandTypeSchema,
    issuedAt: IsoDateTimeSchema,
    expiresAt: IsoDateTimeSchema,
    nonce: z.string().min(16).max(128),
    idempotencyKey: z.string().min(8).max(128),
    payload: z.record(z.unknown()),
    signature: z.string().min(1),
  })
  .strict();

export type CommandEnvelope = z.infer<typeof CommandEnvelopeSchema>;

export const CommandResultSchema = z
  .object({
    commandId: UuidV4Schema,
    requestId: UuidV4Schema,
    ok: z.boolean(),
    errorCode: z.string().optional(),
    errorMessage: z.string().optional(),
    data: z.unknown().optional(),
    latencyMs: z.number().nonnegative(),
  })
  .strict();

export type CommandResult = z.infer<typeof CommandResultSchema>;

export const ListSessionsPayloadSchema = z
  .object({
    limit: z.number().int().min(1).max(100).optional(),
    cursor: z.string().max(256).optional(),
    channel: z.string().max(64).optional(),
    status: z.string().max(64).optional(),
  })
  .strict();

export type ListSessionsPayload = z.infer<typeof ListSessionsPayloadSchema>;

export const GetSessionPayloadSchema = z
  .object({
    sessionId: z.string().min(1).max(256),
  })
  .strict();

export type GetSessionPayload = z.infer<typeof GetSessionPayloadSchema>;

export const AskReadonlyPayloadSchema = z
  .object({
    prompt: z.string().min(1).max(12000),
    sessionId: z.string().min(1).max(256).nullable().optional(),
    timeoutSeconds: z.number().int().min(1).max(120).optional(),
  })
  .strict();

export type AskReadonlyPayload = z.infer<typeof AskReadonlyPayloadSchema>;
