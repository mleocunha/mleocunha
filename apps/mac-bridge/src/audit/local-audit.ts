import type { AuditEvent } from "@relatasoft/contracts";
import { generateUuidV4 } from "@relatasoft/crypto";
import type { Logger } from "@relatasoft/logging";
import { safeDetails } from "@relatasoft/logging";

export class LocalAuditLog {
  private readonly events: AuditEvent[] = [];

  public constructor(private readonly logger: Logger) {}

  public append(
    partial: Omit<AuditEvent, "auditId" | "timestamp"> & {
      details?: Record<string, unknown>;
    },
  ): AuditEvent {
    const event: AuditEvent = {
      auditId: `aud_${generateUuidV4()}`,
      timestamp: new Date().toISOString(),
      actor: partial.actor,
      source: partial.source,
      requestId: partial.requestId,
      operation: partial.operation,
      outcome: partial.outcome,
      riskLevel: partial.riskLevel,
      ...(partial.target !== undefined ? { target: partial.target } : {}),
      ...(partial.payloadHash !== undefined ? { payloadHash: partial.payloadHash } : {}),
      ...(partial.approvalId !== undefined ? { approvalId: partial.approvalId } : {}),
      ...(partial.details !== undefined
        ? { details: safeDetails(partial.details) }
        : {}),
    };
    this.events.push(event);
    this.logger.info(
      {
        auditId: event.auditId,
        requestId: event.requestId,
        operation: event.operation,
        outcome: event.outcome,
      },
      "audit",
    );
    return event;
  }

  public list(): readonly AuditEvent[] {
    return this.events;
  }
}
