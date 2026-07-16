import { z } from "zod";

/** Approval model reserved for Phase 2+; schemas exist for shared contracts. */
export const ApprovalPreviewSchema = z
  .object({
    channel: z.string(),
    recipient_name: z.string(),
    recipient_masked: z.string(),
    message: z.string(),
    attachments: z.array(z.string()),
  })
  .strict();

export type ApprovalPreview = z.infer<typeof ApprovalPreviewSchema>;

export const ApprovalRequiredResponseSchema = z
  .object({
    status: z.literal("approval_required"),
    approval_id: z.string(),
    expires_at: z.string(),
    operation: z.string(),
    payload_hash: z.string(),
    preview: ApprovalPreviewSchema,
  })
  .strict();

export type ApprovalRequiredResponse = z.infer<typeof ApprovalRequiredResponseSchema>;

export const ConfirmActionBodySchema = z
  .object({
    approval_id: z.string(),
    confirmation: z.literal(true),
  })
  .strict();

export type ConfirmActionBody = z.infer<typeof ConfirmActionBodySchema>;
