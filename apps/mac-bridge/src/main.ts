import { createLogger } from "@relatasoft/logging";
import { createOpenClawClient } from "@relatasoft/openclaw-adapter";
import { loadBridgeConfig } from "./config.js";
import { LocalAuditLog } from "./audit/local-audit.js";
import {
  createKeychainReader,
  createKeychainSecretProvider,
} from "./keychain/secrets.js";
import { BridgeTransport } from "./transport/bridge-transport.js";

async function main(): Promise<void> {
  const logger = createLogger({
    name: "mac-bridge",
    level: process.env["LOG_LEVEL"] ?? "info",
  });

  const keychain = await createKeychainReader();
  const deviceId = process.env["BRIDGE_DEVICE_ID"] ?? "macbook-mauro";
  const secrets = createKeychainSecretProvider(keychain, deviceId);
  const config = await loadBridgeConfig(process.env, secrets);

  const client = createOpenClawClient(
    config.OPENCLAW_MODE === "mock"
      ? { mode: "mock" }
      : {
          mode: "http",
          baseUrl: config.OPENCLAW_BASE_URL,
        },
  );

  const audit = new LocalAuditLog(logger);
  const transport = new BridgeTransport(config, client, audit, logger);
  transport.start();

  const shutdown = (): void => {
    logger.info("Shutting down Mac Bridge");
    transport.stop();
    process.exit(0);
  };
  process.on("SIGINT", shutdown);
  process.on("SIGTERM", shutdown);
}

main().catch((error: unknown) => {
  console.error(error);
  process.exit(1);
});
