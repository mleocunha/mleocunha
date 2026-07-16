import { afterAll, beforeAll, describe, expect, it } from "vitest";
import WebSocket from "ws";
import {
  createHash,
  generateKeyPairSync,
  randomBytes,
  sign,
} from "node:crypto";
import { buildApp, type BuiltGateway } from "../../apps/action-gateway/src/app.js";
import { loadConfigFromPartial } from "../../apps/action-gateway/src/config.js";
import {
  CommandEnvelopeSchema,
  DeviceAuthChallengeSchema,
} from "@relatasoft/contracts";
import { verifyCommandSignature } from "@relatasoft/crypto";
import { MockOpenClawClient } from "../../apps/openclaw-adapter/src/index.js";
import {
  CommandSecurity,
  executeCommand,
} from "../../apps/mac-bridge/src/security/command-security.js";

function ed25519Pair(): { publicKeyBase64: string; privateKeyBase64: string } {
  const { publicKey, privateKey } = generateKeyPairSync("ed25519");
  return {
    publicKeyBase64: publicKey.export({ type: "spki", format: "der" }).toString("base64"),
    privateKeyBase64: privateKey.export({ type: "pkcs8", format: "der" }).toString("base64"),
  };
}

describe("action gateway integration", () => {
  const token = randomBytes(32).toString("hex");
  const tokenHash = createHash("sha256").update(token).digest("hex");
  const keys = ed25519Pair();
  const signingSecret = randomBytes(32).toString("base64");
  let gateway: BuiltGateway;
  let baseUrl: string;
  let mockClient: MockOpenClawClient;
  let security: CommandSecurity;
  let bridgeSocket: WebSocket | null = null;

  beforeAll(async () => {
    const config = loadConfigFromPartial({
      GPT_ACTION_TOKEN_HASH: tokenHash,
      DEVICE_PUBLIC_KEY: keys.publicKeyBase64,
      COMMAND_SIGNING_SECRET: signingSecret,
      DEVICE_ID: "macbook-test",
    });
    gateway = await buildApp(config);
    await gateway.app.listen({ host: "127.0.0.1", port: 0 });
    const address = gateway.app.server.address();
    if (address === null || typeof address === "string") {
      throw new Error("Failed to bind test server");
    }
    baseUrl = `http://127.0.0.1:${address.port}`;

    mockClient = new MockOpenClawClient();
    security = new CommandSecurity(Buffer.from(signingSecret, "base64"));

    await new Promise<void>((resolve, reject) => {
      const ws = new WebSocket(`ws://127.0.0.1:${address.port}/v1/device/connect`);
      bridgeSocket = ws;
      ws.on("open", () => undefined);
      ws.on("error", reject);
      ws.on("message", (data) => {
        void (async () => {
          const parsed = JSON.parse(data.toString()) as unknown;
          if (
            typeof parsed === "object" &&
            parsed !== null &&
            "type" in parsed &&
            (parsed as { type: string }).type === "auth_challenge"
          ) {
            const challenge = DeviceAuthChallengeSchema.parse(parsed);
            const message = `${challenge.challengeId}:${challenge.nonce}:macbook-test`;
            const signature = sign(
              null,
              Buffer.from(message, "utf8"),
              {
                key: Buffer.from(keys.privateKeyBase64, "base64"),
                format: "der",
                type: "pkcs8",
              },
            ).toString("base64url");
            ws.send(
              JSON.stringify({
                type: "auth_response",
                deviceId: "macbook-test",
                challengeId: challenge.challengeId,
                signature,
              }),
            );
          } else if (
            typeof parsed === "object" &&
            parsed !== null &&
            "type" in parsed &&
            (parsed as { type: string }).type === "auth_result"
          ) {
            resolve();
          } else if (
            typeof parsed === "object" &&
            parsed !== null &&
            "type" in parsed &&
            (parsed as { type: string }).type === "command"
          ) {
            const envelope = CommandEnvelopeSchema.parse(
              (parsed as { envelope: unknown }).envelope,
            );
            expect(verifyCommandSignature(envelope, Buffer.from(signingSecret, "base64"))).toBe(
              true,
            );
            const validated = security.validate(envelope);
            const started = Date.now();
            try {
              const data = await executeCommand(mockClient, validated);
              ws.send(
                JSON.stringify({
                  type: "command_result",
                  result: {
                    commandId: validated.commandId,
                    requestId: validated.requestId,
                    ok: true,
                    data,
                    latencyMs: Date.now() - started,
                  },
                }),
              );
            } catch (error) {
              ws.send(
                JSON.stringify({
                  type: "command_result",
                  result: {
                    commandId: validated.commandId,
                    requestId: validated.requestId,
                    ok: false,
                    errorCode: "INTERNAL_ERROR",
                    errorMessage: error instanceof Error ? error.message : "error",
                    latencyMs: Date.now() - started,
                  },
                }),
              );
            }
          }
        })();
      });
    });
  });

  afterAll(async () => {
    bridgeSocket?.close();
    await gateway.app.close();
  });

  async function api(
    path: string,
    init: RequestInit = {},
  ): Promise<{ status: number; json: unknown }> {
    const headers = new Headers(init.headers);
    if (!headers.has("authorization")) {
      headers.set("authorization", `Bearer ${token}`);
    }
    const res = await fetch(`${baseUrl}${path}`, { ...init, headers });
    return { status: res.status, json: await res.json() };
  }

  it("serves health without auth", async () => {
    const res = await fetch(`${baseUrl}/health`);
    expect(res.status).toBe(200);
    const body = (await res.json()) as { status: string };
    expect(body.status).toBe("ok");
  });

  it("rejects missing bearer token", async () => {
    const res = await fetch(`${baseUrl}/v1/openclaw/status`);
    expect(res.status).toBe(401);
  });

  it("returns openclaw status via bridge", async () => {
    const { status, json } = await api("/v1/openclaw/status");
    expect(status).toBe(200);
    const body = json as { online: boolean; activeModel: string; auditId: string };
    expect(body.online).toBe(true);
    expect(body.activeModel).toBe("qwen");
    expect(body.auditId).toMatch(/^aud_/);
  });

  it("lists channels", async () => {
    const { status, json } = await api("/v1/openclaw/channels");
    expect(status).toBe(200);
    const body = json as { channels: Array<{ id: string }> };
    expect(body.channels.length).toBeGreaterThan(0);
  });

  it("lists sessions", async () => {
    const { status, json } = await api("/v1/openclaw/sessions?limit=10");
    expect(status).toBe(200);
    const body = json as { sessions: unknown[] };
    expect(Array.isArray(body.sessions)).toBe(true);
  });

  it("handles ask-readonly", async () => {
    const { status, json } = await api("/v1/openclaw/ask-readonly", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ prompt: "Verifique o estado do canal WhatsApp." }),
    });
    expect(status).toBe(200);
    const body = json as { answer: string; model: string };
    expect(body.model).toBe("qwen");
    expect(body.answer.length).toBeGreaterThan(0);
  });

  it("is idempotent for repeated keys", async () => {
    const headers = {
      authorization: `Bearer ${token}`,
      "content-type": "application/json",
      "idempotency-key": "ask-once-1",
    };
    const body = JSON.stringify({ prompt: "Status do gateway local." });
    const a = await api("/v1/openclaw/ask-readonly", { method: "POST", headers, body });
    const b = await api("/v1/openclaw/ask-readonly", { method: "POST", headers, body });
    expect(a.status).toBe(200);
    expect(b.status).toBe(200);
    expect(b.json).toEqual(a.json);
  });

  it("rejects idempotency key reuse with different payload", async () => {
    const headers = {
      authorization: `Bearer ${token}`,
      "content-type": "application/json",
      "idempotency-key": "ask-conflict-1",
    };
    await api("/v1/openclaw/ask-readonly", {
      method: "POST",
      headers,
      body: JSON.stringify({ prompt: "Primeira pergunta." }),
    });
    const second = await api("/v1/openclaw/ask-readonly", {
      method: "POST",
      headers,
      body: JSON.stringify({ prompt: "Segunda pergunta diferente." }),
    });
    expect(second.status).toBe(409);
  });

  it("writes audit events", async () => {
    expect(gateway.audit.list().length).toBeGreaterThan(0);
  });
});
