const fs = require('fs');
const path = require('path');

function checkFile(file) {
  const content = fs.readFileSync(path.join('./src/data', file), 'utf-8');
  const lines = content.split('\n').filter(l => l.trim());
  if (lines.length <= 1) return;

  const headerCount = lines[0].split(',').length;

  let withImage = 0;
  let missing = 0;

  for (let i = 1; i < lines.length; i++) {
    const cols = lines[i].split(',');
    const lastCol = cols[cols.length - 1].trim();

    if (lastCol && (lastCol.startsWith('thumbnails/') || lastCol.startsWith('http'))) {
      withImage++;
    } else {
      missing++;
    }
  }

  console.log(`${file}: ${withImage} with image, ${missing} missing (header cols: ${headerCount})`);
}

const files = fs.readdirSync('./src/data').filter(f => f.endsWith('.csv'));
files.forEach(checkFile);