const csvSources = import.meta.glob("../data/*.csv", {
  query: "?raw",
  import: "default"
});

const FIELD_REMAP = {
  performance_core_clock: "core_clock",
  performance_core_boost_clock: "boost_clock",
  integrated_graphics: "graphics",
  "response_time_(g2g)": "response_time",
};

const SKIP_COLS = new Set([
  "web_scraper_order", "web_scraper_start_url", "pagination",
]);

const DEDUP_RE = /^(.+)\d+$/;

const SCAN_NOISE = new Set([
  "compare", "quick view", "free delivery on your entire basket", "free delivery",
  "add to basket", "pre order", "key specifications", "in stock",
  "get it tomorrow", "tomorrow", "replacement", "scan finance",
  "paypal credit options available", "paypal", "no customer review",
  "out of stock", "48hr",
]);

export async function loadCSV(file) {
  const loader = csvSources[`../data/${file}`];
  if (!loader) return [];

  const text = await loader();
  const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  if (lines.length < 2) return [];

  const headers = parseCSVLine(lines[0]);
  const rows = lines.slice(1);

  const isRaw = headers.includes("web_scraper_order");
  const isScan = isRaw && headers.includes("data") && !headers.includes("name");

  if (isScan) return parseScan(headers, rows);
  if (isRaw) return parseRaw(headers, rows);
  return parseClean(headers, rows);
}

function parseClean(headers, rows) {
  const skipIdx = headers.indexOf("skip");
  return rows
    .map(row => {
      const vals = parseCSVLine(row);
      if (!vals[0]?.trim()) return null;
      if (skipIdx !== -1 && String(vals[skipIdx] ?? "").trim() === "1") return null;
      const item = {};
      for (let i = 0; i < headers.length; i++) {
        item[headers[i]] = normalizeValue(vals[i] ?? "");
      }
      return item;
    })
    .filter(Boolean);
}

function parseRaw(headers, rows) {
  const firstVals = rows[0] ? parseCSVLine(rows[0]) : [];

  let priceCol = -1;
  for (const c of ["price", "price2", "price3", "price4"]) {
    const idx = headers.indexOf(c);
    if (idx !== -1) {
      const v = firstVals[idx] ?? "";
      if (v.includes("£")) { priceCol = idx; break; }
    }
  }
  if (priceCol === -1) {
    for (const c of ["price", "price2", "price3", "price4"]) {
      const idx = headers.indexOf(c);
      if (idx !== -1 && firstVals[idx]?.trim()) { priceCol = idx; break; }
    }
  }

  let imageCol = -1;
  for (const c of ["name2", "name4"]) {
    const idx = headers.indexOf(c);
    if (idx !== -1 && (firstVals[idx] ?? "").includes("http")) {
      imageCol = idx;
      break;
    }
  }

  let ratingCol = headers.indexOf("rating");

  return rows
    .map(row => {
      const vals = parseCSVLine(row);
      if (!vals[0]?.trim()) return null;

      const item = {};

      for (let i = 0; i < headers.length; i++) {
        const h = headers[i];
        if (SKIP_COLS.has(h)) continue;

        if (DEDUP_RE.test(h) && h !== "name2" && h !== "name4" && h !== "price2" && h !== "price3" && h !== "price4") {
          const base = h.match(DEDUP_RE)[1];
          if (headers.includes(base) || headers.indexOf(h) > headers.indexOf(base)) continue;
        }

        const raw = (vals[i] ?? "").trim();
        if (!raw) continue;

        if (h === "name") { item.name = raw; continue; }
        if (i === ratingCol) {
          const m = raw.match(/\((\d+)\)/);
          if (m) item.rating = normalizeValue(m[1]);
          continue;
        }
        if (i === imageCol && raw.startsWith("http")) { item.image = raw; continue; }
        if (h === "price" || h === "price2" || h === "price3" || h === "price4") {
          if (i === priceCol) {
            const p = extractPrice(raw);
            if (p) item.price = normalizeValue(p);
          }
          continue;
        }
        if (["name2", "name3", "name4"].includes(h)) continue;

        const mapped = FIELD_REMAP[h] || h;
        const cleaned = stripPrefix(h, raw);
        if (cleaned) item[mapped] = normalizeValue(cleaned);
      }

      if (!item.name?.trim()) return null;
      return item;
    })
    .filter(Boolean);
}

function parseScan(headers, rows) {
  const dataIdx = headers.indexOf("data");
  const priceIdx = headers.indexOf("price");
  const imageIdx = headers.indexOf("image");

  return rows
    .map(row => {
      const vals = parseCSVLine(row);
      if (!vals[0]?.trim()) return null;

      const dataVal = (vals[dataIdx] ?? "").trim();
      const priceVal = (vals[priceIdx] ?? "").trim();
      const imageVal = (vals[imageIdx] ?? "").trim();

      const commaIdx = dataVal.indexOf(",");
      const name = commaIdx > 0 ? dataVal.substring(0, commaIdx).trim() : dataVal;
      if (!name) return null;

      const price = extractPrice(priceVal);
      const item = { name };
      if (price) item.price = normalizeValue(price);
      if (imageVal.startsWith("http")) item.image = imageVal;

      const rowLower = vals.join(" ").toLowerCase();
      if (rowLower.includes("pre order") || rowLower.includes("out of stock")) {
        item.outOfStock = true;
      }

      let attrIdx = 0;
      for (let i = 0; i < headers.length; i++) {
        const h = headers[i];
        if (SKIP_COLS.has(h) || ["data", "price", "image", "image2", "image3", "image4"].includes(h)) continue;
        const v = (vals[i] ?? "").trim();
        if (!v || v.length > 100 || SCAN_NOISE.has(v.toLowerCase())) continue;
        if (v.startsWith("http") || v.startsWith("£")) continue;
        attrIdx++;
        if (attrIdx <= 8) item[`feature${attrIdx}`] = v;
      }

      return item;
    })
    .filter(Boolean);
}

function stripPrefix(header, value) {
  if (!value || typeof value !== "string") return value;
  const prefix = header.replace(/_/g, " ").replace(/\b\w/g, c => c.toUpperCase());
  let result = value;
  if (result.length > prefix.length && result.toLowerCase().startsWith(prefix.toLowerCase())) {
    result = result.slice(prefix.length);
  }
  return result.trim();
}

function extractPrice(value) {
  if (!value || typeof value !== "string") return "";
  const m = value.match(/[\d,.]+/);
  return m ? m[0].replace(/,/g, "") : "";
}

function parseCSVLine(line) {
  const values = [];
  let current = "";
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    const next = line[i + 1];
    if (ch === '"') {
      if (inQuotes && next === '"') { current += '"'; i++; }
      else inQuotes = !inQuotes;
      continue;
    }
    if (ch === "," && !inQuotes) { values.push(current); current = ""; continue; }
    current += ch;
  }
  values.push(current);
  return values;
}

function normalizeValue(value) {
  const trimmed = value.trim();
  if (trimmed === "") return "";
  const numeric = Number(trimmed);
  return Number.isNaN(numeric) ? trimmed : numeric;
}
