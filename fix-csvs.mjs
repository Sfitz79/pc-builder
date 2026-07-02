import fs from 'fs';
import path from 'path';

const DATA_DIR = 'src/data';
const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));

files.forEach(file => {
  const filePath = path.join(DATA_DIR, file);
  const content = fs.readFileSync(filePath, 'utf-8');
  
  // If we see the corruption pattern
  if (content.includes('\r,') || content.includes('\n,')) {
    console.log(`Fixing ${file}...`);
    // Split by lines, but carefully handle the corruption
    const lines = content.split(/\r|\n/).filter(l => l.length > 0);
    const fixedLines = [];
    
    for (let i = 0; i < lines.length; i++) {
      let line = lines[i];
      // Join if next line starts with a comma
      while (i + 1 < lines.length && lines[i+1].startsWith(',')) {
        line = line + lines[i+1];
        i++;
      }
      fixedLines.push(line);
    }
    
    fs.writeFileSync(filePath, fixedLines.join('\n'), 'utf-8');
  }
});
