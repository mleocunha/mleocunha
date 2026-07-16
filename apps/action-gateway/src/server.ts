import { loadConfig } from "./config.js";
import { buildApp } from "./app.js";

async function main(): Promise<void> {
  const config = loadConfig();
  const { app } = await buildApp(config);
  await app.listen({ host: config.HOST, port: config.PORT });
  app.log.info(
    { host: config.HOST, port: config.PORT },
    "RelataSoft Action Gateway listening",
  );
}

main().catch((error: unknown) => {
  console.error(error);
  process.exit(1);
});
