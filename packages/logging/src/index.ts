import pino, { type Logger, type LoggerOptions } from "pino";

const SECRET_KEYS = new Set([
  "authorization",
  "token",
  "password",
  "secret",
  "apikey",
  "api_key",
  "cookie",
  "privatekey",
  "private_key",
  "openclaw_token",
  "gateway_token",
]);

function redactValue(_key: string, value: unknown): unknown {
  if (typeof value === "string" && value.length > 0) {
    return "[REDACTED]";
  }
  return "[REDACTED]";
}

function redactBindings(bindings: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(bindings)) {
    const normalized = key.toLowerCase().replace(/-/g, "_");
    if (
      SECRET_KEYS.has(normalized) ||
      normalized.includes("token") ||
      normalized.includes("secret")
    ) {
      out[key] = redactValue(key, value);
    } else if (value !== null && typeof value === "object" && !Array.isArray(value)) {
      out[key] = redactBindings(value as Record<string, unknown>);
    } else {
      out[key] = value;
    }
  }
  return out;
}

export type CreateLoggerOptions = {
  name: string;
  level?: string;
};

export function createLogger(options: CreateLoggerOptions): Logger {
  const opts: LoggerOptions = {
    name: options.name,
    level: options.level ?? "info",
    redact: {
      paths: [
        "req.headers.authorization",
        "headers.authorization",
        "*.token",
        "*.secret",
        "*.password",
        "*.privateKey",
        "*.gatewayToken",
      ],
      censor: "[REDACTED]",
    },
    serializers: {
      err: pino.stdSerializers.err,
    },
  };

  return pino(opts);
}

export function safeDetails(details: Record<string, unknown>): Record<string, unknown> {
  return redactBindings(details);
}

export type { Logger };
