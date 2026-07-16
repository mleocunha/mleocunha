import {
  AppError,
  ErrorCodes,
  PHASE1_COMMAND_TYPES,
  type CommandType,
  type Phase1CommandType,
} from "@relatasoft/contracts";

const ALLOWED = new Set<string>(PHASE1_COMMAND_TYPES);

/** Forbidden public tool names — never map these. */
export const FORBIDDEN_TOOLS = [
  "exec",
  "shell",
  "bash",
  "zsh",
  "process",
  "runCommand",
  "invokeAnyTool",
  "rawGatewayCall",
  "arbitraryHttpRequest",
  "readAnyFile",
  "writeAnyFile",
  "deleteAnyFile",
  "installPlugin",
  "changeGatewayConfig",
] as const;

export function assertPhase1Command(type: CommandType): asserts type is Phase1CommandType {
  if (!ALLOWED.has(type)) {
    throw new AppError(
      ErrorCodes.UNSUPPORTED_COMMAND,
      `Command type ${type} is not enabled in phase 1`,
      403,
    );
  }
}

export function isForbiddenToolName(name: string): boolean {
  return (FORBIDDEN_TOOLS as readonly string[]).includes(name);
}

/**
 * Readonly ask prompts must not instruct mutation or external send.
 * This is a defense-in-depth heuristic, not a substitute for OpenClaw policy.
 */
const MUTATION_HINT =
  /\b(send|enviar|delete|excluir|rm\s|exec|shell|bash|wget|curl\s+-[A-Za-z]*X|invoke|upload|post\s+to)\b/i;

export function assertReadonlyPrompt(prompt: string): void {
  if (MUTATION_HINT.test(prompt)) {
    throw new AppError(
      ErrorCodes.POLICY_DENIED,
      "ask-readonly prompt appears to request a mutating or external action",
      403,
    );
  }
}
