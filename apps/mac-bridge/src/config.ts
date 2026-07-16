import { z } from "zod";

const EnvSchema = z
  .object({
    NODE_ENV: z.enum(["development", "test", "production"]).default("development"),
    GATEWAY_WS_URL: z.string().url(),
    OPENCLAW_BASE_URL: z.string().url().default("http://127.0.0.1:18789"),
    OPENCLAW_MODE: z.enum(["mock", "http"]).default("mock"),
    BRIDGE_DEVICE_ID: z.string().min(1),
    DEVICE_PRIVATE_KEY: z.string().min(1).optional(),
    COMMAND_SIGNING_SECRET: z.string().min(1),
    LOG_LEVEL: z.string().default("info"),
    RECONNECT_MIN_MS: z.coerce.number().int().positive().default(1000),
    RECONNECT_MAX_MS: z.coerce.number().int().positive().default(30000),
  })
  .strict();

export type BridgeConfig = z.infer<typeof EnvSchema> & {
  commandSigningSecret: Buffer;
  devicePrivateKey: string;
};

export type SecretProvider = {
  getDevicePrivateKey(): Promise<string | null>;
};

export async function loadBridgeConfig(
  env: NodeJS.ProcessEnv = process.env,
  secrets?: SecretProvider,
): Promise<BridgeConfig> {
  const parsed = EnvSchema.safeParse(env);
  if (!parsed.success) {
    const issues = parsed.error.issues.map((i) => `${i.path.join(".")}: ${i.message}`).join("; ");
    throw new Error(`Invalid bridge configuration: ${issues}`);
  }
  const data = parsed.data;
  const secret = Buffer.from(data.COMMAND_SIGNING_SECRET, "base64");
  if (secret.length < 32) {
    throw new Error("COMMAND_SIGNING_SECRET must decode to at least 32 bytes");
  }

  let devicePrivateKey = data.DEVICE_PRIVATE_KEY;
  if (devicePrivateKey === undefined && secrets !== undefined) {
    devicePrivateKey = (await secrets.getDevicePrivateKey()) ?? undefined;
  }
  if (devicePrivateKey === undefined || devicePrivateKey.length === 0) {
    throw new Error("DEVICE_PRIVATE_KEY missing (set env for dev or Keychain on macOS)");
  }

  return {
    ...data,
    commandSigningSecret: secret,
    devicePrivateKey,
  };
}
