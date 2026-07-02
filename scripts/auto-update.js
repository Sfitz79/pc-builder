import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { spawn } from 'child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT_DIR = path.join(__dirname, '..');
const STATE_FILE = path.join(ROOT_DIR, 'aioupdate-state.json');
const ORCHESTRATOR_SCRIPT = path.join(__dirname, 'aioupdate.js');
const UPDATE_INTERVAL_DAYS = 5;

function shouldUpdate() {
  if (!fs.existsSync(STATE_FILE)) return true;
  try {
    const state = JSON.parse(fs.readFileSync(STATE_FILE, 'utf-8'));
    if (!state.lastSuccess) return true;
    const now = Date.now();
    const diffDays = (now - state.lastSuccess) / (1000 * 60 * 60 * 24);
    return diffDays >= UPDATE_INTERVAL_DAYS;
  } catch {
    return true;
  }
}

async function runUpdate() {
  console.log(`[Auto-Update] Triggering aioupdate (every ${UPDATE_INTERVAL_DAYS} days)...`);

  const child = spawn('node', [ORCHESTRATOR_SCRIPT, '--once', '--force'], {
    cwd: ROOT_DIR,
    detached: true,
    stdio: 'ignore',
  });

  child.unref();
  console.log('[Auto-Update] AIO update started in background.');
}

if (shouldUpdate()) {
  runUpdate();
} else {
  const state = JSON.parse(fs.readFileSync(STATE_FILE, 'utf-8'));
  const nextRun = new Date(state.lastSuccess + UPDATE_INTERVAL_DAYS * 24 * 60 * 60 * 1000);
  console.log(`[Auto-Update] Data is up to date. Next update due: ${nextRun.toISOString()}`);
}
