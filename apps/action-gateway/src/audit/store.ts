import type { AuditEvent } from "@relatasoft/contracts";
import { generateUuidV4 } from "@relatasoft/crypto";

export class AuditStore {
  private readonly events: AuditEvent[] = [];

  public append(
    partial: Omit<AuditEvent, "auditId" | "timestamp"> & {
      auditId?: string;
      timestamp?: string;
    },
  ): AuditEvent {
    const event: AuditEvent = {
      auditId: partial.auditId ?? `aud_${generateUuidV4()}`,
      timestamp: partial.timestamp ?? new Date().toISOString(),
      actor: partial.actor,
      source: partial.source,
      requestId: partial.requestId,
      operation: partial.operation,
      outcome: partial.outcome,
      riskLevel: partial.riskLevel,
      ...(partial.target !== undefined ? { target: partial.target } : {}),
      ...(partial.payloadHash !== undefined ? { payloadHash: partial.payloadHash } : {}),
      ...(partial.approvalId !== undefined ? { approvalId: partial.approvalId } : {}),
      ...(partial.details !== undefined ? { details: partial.details } : {}),
    };
    this.events.push(event);
    return event;
  }

  public list(): readonly AuditEvent[] {
    return this.events;
  }

  public findByRequestId(requestId: string): AuditEvent[] {
    return this.events.filter((e) => e.requestId === requestId);
  }
}
