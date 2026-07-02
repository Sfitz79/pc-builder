import fs from 'fs';
const files = fs.readdirSync('src/data').filter(f => f.endsWith('.csv'));
for (const f of files) {
  const h = fs.readFileSync('src/data/' + f, 'utf-8').split('\n')[0];
  console.log(f + ': ' + h);
}
