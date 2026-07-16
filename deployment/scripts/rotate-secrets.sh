#!/usr/bin/env bash
set -euo pipefail

echo "Generate a new GPT Action token and update GPT_ACTION_TOKEN_HASH on the server."
echo "Generate a new Ed25519 device keypair; update DEVICE_PUBLIC_KEY on server and Keychain on Mac."
echo "Generate a new COMMAND_SIGNING_SECRET shared by Gateway and Bridge."
node --input-type=module <<'EOF'
import { createHash, generateKeyPairSync, randomBytes } from "node:crypto";
const token = randomBytes(32).toString("hex");
console.log("GPT_ACTION_TOKEN=" + token);
console.log("GPT_ACTION_TOKEN_HASH=" + createHash("sha256").update(token).digest("hex"));
console.log("COMMAND_SIGNING_SECRET=" + randomBytes(32).toString("base64"));
const { publicKey, privateKey } = generateKeyPairSync("ed25519");
console.log("DEVICE_PUBLIC_KEY=" + publicKey.export({ type: "spki", format: "der" }).toString("base64"));
console.log("DEVICE_PRIVATE_KEY=" + privateKey.export({ type: "pkcs8", format: "der" }).toString("base64"));
EOF
