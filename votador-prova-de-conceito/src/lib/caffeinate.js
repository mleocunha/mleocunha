import { spawn } from 'node:child_process';

/**
 * Keep the macOS display awake for the duration of a Votador run.
 * When the screen blanks, headed Chrome / Playwright sessions stall.
 *
 * Uses `caffeinate -d` (prevent display sleep). No-op on non-darwin.
 *
 * @param {{ info?: Function, warn?: Function }} [logger]
 * @returns {{ active: boolean, stop: () => void }}
 */
export function startDisplayCaffeinate(logger) {
  if (process.platform !== 'darwin') {
    return { active: false, stop() {} };
  }

  try {
    const child = spawn('caffeinate', ['-d'], {
      stdio: 'ignore',
      detached: false,
    });

    let stopped = false;
    const stop = () => {
      if (stopped) {
        return;
      }
      stopped = true;
      try {
        if (!child.killed) {
          child.kill('SIGTERM');
        }
      } catch {
        /* already exited */
      }
    };

    child.on('error', (err) => {
      logger?.warn?.('caffeinate falhou ao iniciar', {
        error: String(err.message || err),
      });
      stopped = true;
    });

    logger?.info?.('caffeinate -d ativo (tela não apaga durante a votação)');
    return { active: true, stop };
  } catch (err) {
    logger?.warn?.('Não foi possível iniciar caffeinate -d', {
      error: String(err.message || err),
    });
    return { active: false, stop() {} };
  }
}
