const { readFileSync, writeFileSync, existsSync } = require('fs');
const { execSync } = require('child_process');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PKG_PATH = path.join(ROOT, 'package.json');
const VERSION_PATH = path.join(ROOT, 'src', 'version.json');

const pkg = JSON.parse(readFileSync(PKG_PATH, 'utf8'));
const parts = pkg.version.split('.').map(Number);
parts[2] = (parts[2] || 0) + 1;
if (parts[2] >= 100) { parts[2] = 0; parts[1] = (parts[1] || 0) + 1; }
const newVersion = parts.join('.');

pkg.version = newVersion;
writeFileSync(PKG_PATH, JSON.stringify(pkg, null, 2) + '\n');

let commit = '';
try {
  commit = execSync('git rev-parse --short HEAD', { cwd: ROOT, encoding: 'utf8' }).trim();
} catch {}

const versionInfo = {
  version: newVersion,
  buildDate: new Date().toISOString(),
  commit
};
writeFileSync(VERSION_PATH, JSON.stringify(versionInfo, null, 2) + '\n');

console.log(`Version bumped to ${newVersion}`);
