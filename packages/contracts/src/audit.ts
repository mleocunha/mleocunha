import { z } from "zod";

export const AuditSourceSchema = z.enum([
  "gpt_action",
  "gateway",
  "mac_bridge",
  "openclaw",
]);

export type AuditSource = z.infer<typeof AuditSourceSchema>;

export const AuditOutcomeSchema = z.enum([
  "accepted",
  "rejected",
  "prepared",
  "confirmed",
  "executed",
  "failed",
  "cancelled",
]);

export type AuditOutcome = z.infer<typeof AuditOutcomeSchema>;

export const RiskLevelSchema = z.union([
  z.literal(0),
  z.literal(1),
  z.literal(2),
  z.literal(3),
]);

export type RiskLevel = z.infer<typeof RiskLevelSchema>;

export const AuditEventSchema = z
  .object({
    auditId: z.string(),
    timestamp: z.string(),
    actor: z.string(),
    source: AuditSourceSchema,
    requestId: z.string(),
    operation: z.string(),
    target: z.string().optional(),
    outcome: AuditOutcomeSchema,
    riskLevel: RiskLevelSchema,
    payloadHash: z.string().optional(),
    approvalId: z.string().optional(),
    details: z.record(z.unknown()).optional(),
  })
  .strict();

export type AuditEvent = z.infer<typeof AuditEventSchema>;
