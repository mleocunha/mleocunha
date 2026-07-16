import type { Phase1CommandType } from "@relatasoft/contracts";

export type RiskLevel = 0 | 1 | 2 | 3;

const RISK: Record<Phase1CommandType, RiskLevel> = {
  GET_STATUS: 0,
  LIST_CHANNELS: 0,
  LIST_SESSIONS: 0,
  GET_SESSION: 0,
  ASK_READONLY: 0,
};

export function riskForCommand(type: Phase1CommandType): RiskLevel {
  return RISK[type];
}
