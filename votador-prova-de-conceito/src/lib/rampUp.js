/**
 * Ramp-up speed presets for adaptive Chrome concurrency.
 * Controls how quickly workers are added after healthy successes.
 */

export const RAMP_UP_PRESETS = {
  slow: {
    id: 'slow',
    label: 'Lenta',
    scaleUpEveryMs: 15000,
    scaleDownEveryMs: 8000,
    healthySuccessesNeeded: 3,
    hint: '~15s entre aceleração; 3 sucessos saudáveis',
  },
  normal: {
    id: 'normal',
    label: 'Normal',
    scaleUpEveryMs: 8000,
    scaleDownEveryMs: 5000,
    healthySuccessesNeeded: 2,
    hint: '~8s entre aceleração; 2 sucessos saudáveis',
  },
  fast: {
    id: 'fast',
    label: 'Rápida',
    scaleUpEveryMs: 3000,
    scaleDownEveryMs: 4000,
    healthySuccessesNeeded: 1,
    hint: '~3s entre aceleração; 1 sucesso saudável',
  },
  aggressive: {
    id: 'aggressive',
    label: 'Agressiva',
    scaleUpEveryMs: 1000,
    scaleDownEveryMs: 3000,
    healthySuccessesNeeded: 1,
    hint: '~1s entre aceleração; 1 sucesso saudável',
  },
};

/**
 * @param {string|undefined|null} speed
 * @param {{ scaleUpEveryMs?: number, scaleDownEveryMs?: number, healthySuccessesNeeded?: number }} [overrides]
 */
export function resolveRampUpConfig(speed, overrides = {}) {
  const key = String(speed || 'normal').toLowerCase();
  const preset = RAMP_UP_PRESETS[key] || RAMP_UP_PRESETS.normal;

  let scaleUpEveryMs = Number(overrides.scaleUpEveryMs);
  if (!Number.isFinite(scaleUpEveryMs) || scaleUpEveryMs <= 0) {
    scaleUpEveryMs = preset.scaleUpEveryMs;
  }
  scaleUpEveryMs = Math.max(500, Math.min(60000, scaleUpEveryMs));

  let scaleDownEveryMs = Number(overrides.scaleDownEveryMs);
  if (!Number.isFinite(scaleDownEveryMs) || scaleDownEveryMs <= 0) {
    scaleDownEveryMs = preset.scaleDownEveryMs;
  }
  scaleDownEveryMs = Math.max(500, Math.min(60000, scaleDownEveryMs));

  let healthySuccessesNeeded = Number(overrides.healthySuccessesNeeded);
  if (!Number.isFinite(healthySuccessesNeeded) || healthySuccessesNeeded < 1) {
    healthySuccessesNeeded = preset.healthySuccessesNeeded;
  }
  healthySuccessesNeeded = Math.max(1, Math.min(10, Math.round(healthySuccessesNeeded)));

  return {
    rampUpSpeed: preset.id,
    label: preset.label,
    hint: preset.hint,
    scaleUpEveryMs,
    scaleDownEveryMs,
    healthySuccessesNeeded,
  };
}
