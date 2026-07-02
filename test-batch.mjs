import { processBatch } from './batch-scraper.mjs';

const csvFile = process.argv[2] || 'case-fan.csv';
const startIdx = parseInt(process.argv[3] || '1');
const batchSize = 5; // small test batch

console.log(`Testing: ${csvFile} starting at line ${startIdx}, batch of ${batchSize}`);
const result = await processBatch(csvFile, startIdx, batchSize);
console.log(`\nResult: ${result.done} OK, ${result.failed} FAIL, nextIdx=${result.nextIdx}, totalLeft=${result.totalLeft}`);
