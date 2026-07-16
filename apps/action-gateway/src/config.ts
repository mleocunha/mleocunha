import { z } from "zod";

const EnvSchema = z
  .object({
    NODE_ENV: z.enum(["development", "test", "production"]).default("development"),
    PORT: z.coerce.number().int().positive().default(8788),
    HOST: z.string().default("127.0.0.1"),
    PUBLIC_BASE_URL: z.string().url().default("http://127.0.0.1:8788"),
    LOG_LEVEL: z.string().default("info"),
    GPT_ACTION_TOKEN_HASH: z.string().min(64).max(64),
    DEVICE_ID: z.string().min(1).default("macbook-mauro"),
    DEVICE_PUBLIC_KEY: z.string().min(1),
    COMMAND_SIGNING_SECRET: z.string().min(1),
    APPROVAL_TTL_SECONDS: z.coerce.number().int().positive().default(300),
    COMMAND_TTL_SECONDS: z.coerce.number().int().positive().default(60),
    MAX_BODY_BYTES: z.coerce.number().int().positive().default(262144),
    RATE_LIMIT_MAX: z.coerce.number().int().positive().default(60),
    RATE_LIMIT_WINDOW_MS: z.coerce.number().int().positive().default(60000),
    COMMAND_TIMEOUT_MS: z.coerce.number().int().positive().default(30000),
  })
  .strict();

export type GatewayConfig = z.infer<typeof EnvSchema> & {
  commandSigningSecret: Buffer;
  serviceVersion: string;
};

export function loadConfig(env: NodeJS.ProcessEnv = process.env): GatewayConfig {
  const parsed = EnvSchema.safeParse(env);
  if (!parsed.success) {
    const issues = parsed.error.issues.map((i) => `${i.path.join(".")}: ${i.message}`).join("; ");
    throw new Error(`Invalid configuration: ${issues}`);
  }
  const data = parsed.data;
  const secret = Buffer.from(data.COMMAND_SIGNING_SECRET, "base64");
  if (secret.length < 32) {
    throw new Error("COMMAND_SIGNING_SECRET must decode to at least 32 bytes");
  }
  return {
    ...data,
    commandSigningSecret: secret,
    serviceVersion: "0.1.0",
  };
}

export function loadConfigFromPartial(
  overrides: Partial<Record<keyof z.infer<typeof EnvSchema>, string | number>> & {
    GPT_ACTION_TOKEN_HASH: string;
    DEVICE_PUBLIC_KEY: string;
    COMMAND_SIGNING_SECRET: string;
  },
): GatewayConfig {
  return loadConfig({
    NODE_ENV: "test",
    PORT: "8788",
    HOST: "127.0.0.1",
    PUBLIC_BASE_URL: "http://127.0.0.1:8788",
    LOG_LEVEL: "silent",
    DEVICE_ID: "macbook-mauro",
    APPROVAL_TTL_SECONDS: "300",
    COMMAND_TTL_SECONDS: "60",
    MAX_BODY_BYTES: "262144",
    RATE_LIMIT_MAX: "1000",
    RATE_LIMIT_WINDOW_MS: "60000",
    COMMAND_TIMEOUT_MS: "5000",
    ...Object.fromEntries(
      Object.entries(overrides).map(([k, v]) => [k, String(v)]),
    ),
  });
}
