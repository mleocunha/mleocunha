export class AppError extends Error {
  public readonly code: string;
  public readonly statusCode: number;
  public readonly details?: Record<string, unknown>;

  public constructor(
    code: string,
    message: string,
    statusCode = 400,
    details?: Record<string, unknown>,
  ) {
    super(message);
    this.name = "AppError";
    this.code = code;
    this.statusCode = statusCode;
    if (details !== undefined) {
      this.details = details;
    }
  }
}

export const ErrorCodes = {
  UNAUTHORIZED: "UNAUTHORIZED",
  FORBIDDEN: "FORBIDDEN",
  VALIDATION_ERROR: "VALIDATION_ERROR",
  BRIDGE_OFFLINE: "BRIDGE_OFFLINE",
  OPENCLAW_OFFLINE: "OPENCLAW_OFFLINE",
  COMMAND_EXPIRED: "COMMAND_EXPIRED",
  REPLAY_DETECTED: "REPLAY_DETECTED",
  INVALID_SIGNATURE: "INVALID_SIGNATURE",
  IDEMPOTENCY_CONFLICT: "IDEMPOTENCY_CONFLICT",
  RATE_LIMITED: "RATE_LIMITED",
  NOT_FOUND: "NOT_FOUND",
  TIMEOUT: "TIMEOUT",
  POLICY_DENIED: "POLICY_DENIED",
  INTERNAL_ERROR: "INTERNAL_ERROR",
  DEVICE_AUTH_FAILED: "DEVICE_AUTH_FAILED",
  UNSUPPORTED_COMMAND: "UNSUPPORTED_COMMAND",
} as const;

export type ErrorCode = (typeof ErrorCodes)[keyof typeof ErrorCodes];
