/**
 * MASTER UPDATE - Electron Dashboard Launcher
 *
 * Spawns master-update-fast.js as a child process, opens an Electron
 * BrowserWindow that loads the live progress dashboard at localhost:3333.
 *
 * Usage: npm run master-update  (default)
 *        node electron/run-master-update.cjs
 */

const { app, BrowserWindow } = require('electron');
const path = require('path');
const { spawn } = require('child_process');
const http = require('http');

const ROOT = path.join(__dirname, '..');
const DASHBOARD_URL = 'http://localhost:3333';

function waitForServer(url, retries = 90) {
  return new Promise((resolve, reject) => {
    let n = 0;
    const check = () => {
      http.get(url, (r) => { r.resume(); resolve(true); })
        .on('error', () => (++n >= retries ? reject(Error('Server timeout')) : setTimeout(check, 1000)));
    };
    check();
  });
}

app.whenReady().then(async () => {
  const child = spawn('node', [path.join(ROOT, 'master-update-fast.js')], {
    cwd: ROOT, stdio: 'inherit', shell: true,
    env: { ...process.env, ELECTRON_RUN: '1' },
  });

  try { await waitForServer(DASHBOARD_URL); } catch (e) {
    console.error(e.message);
    child.kill(); app.quit(); return;
  }

  const win = new BrowserWindow({
    width: 960, height: 740, title: 'Master Update — Progress',
    autoHideMenuBar: true, backgroundColor: '#0d1117',
    icon: path.join(ROOT, 'public', 'icon.png'),
    webPreferences: { nodeIntegration: false, contextIsolation: true },
  });
  win.loadURL(DASHBOARD_URL);

  child.on('exit', (code) => {
    console.log(`Process exited (${code}) — close window to exit.`);
  });
  child.on('error', (e) => {
    console.error('Spawn error:', e.message);
    if (!win.isDestroyed()) win.close();
    app.quit();
  });
});
app.on('window-all-closed', () => {});
