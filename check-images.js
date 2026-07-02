import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const dataDir = path.join(__dirname, 'src', 'data');
const thumbsDir = path.join(__dirname, 'public', 'thumbnails');

function parseCSVLine(line) {
  const parts = [];
  let current = '';
  let inQuotes = false;
  for (const char of line) {
    if (char === '"') inQuotes = !inQuotes;
    else if (char === ',' && !inQuotes) {
      parts.push(current);
      current = '';
    } else current += char;
  }
  parts.push(current);
  return parts;
}

function parseCSV(content) {
  const lines = [];
  let currentLine = '';
  for (const line of content.split('\n')) {
    if (line.includes('"') && ((line.match(/"/g) || []).length % 2 !== 0)) {
      currentLine += '\n' + line;
    } else {
      if (currentLine) {
        currentLine += '\n' + line;
        lines.push(currentLine);
        currentLine = '';
      } else {
        lines.push(line);
      }
    }
  }
  if (currentLine) lines.push(currentLine);
  return lines;
}

const files = fs.readdirSync(dataDir).filter(f => f.endsWith('.csv'));

for (const file of files) {
  const content = fs.readFileSync(path.join(dataDir, file), 'utf-8');
  const lines = parseCSV(content);
  if (lines.length <= 1) continue;

  const header = parseCSVLine(lines[0]);
  const imageIdx = header.findIndex(h => h.trim() === 'image');
  const nameIdx = header.indexOf('name');

  let missing = 0;
  let hasImage = 0;

  for (let i = 1; i < lines.length; i++) {
    const line = lines[i].trim();
    if (!line) continue;

    const parts = parseCSVLine(line);

    const name = parts[nameIdx]?.replace(/^"|"$/g, '') || '';
    const imageVal = parts[imageIdx]?.replace(/^"|"$/g, '') || '';

    if (!name) continue;
    if (!imageVal) {
      missing++;
    } else {
      const images = imageVal.split(',');
      const hasLocal = images.some(img => img.startsWith('thumbnails/'));
      if (hasLocal || imageVal.startsWith('http')) hasImage++;
      else missing++;
    }
  }
  console.log(`${file}: ${hasImage} with image, ${missing} missing`);
}