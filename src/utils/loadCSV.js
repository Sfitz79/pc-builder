const csvSources = import.meta.glob("../data/*.csv", {
  query: "?raw",
  import: "default"
});

export async function loadCSV(file) {
  const loader = csvSources[`../data/${file}`];
  if (!loader) {
    return [];
  }

  const text = await loader();
  const [headerLine, ...rows] = text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);

  if (!headerLine) {
    return [];
  }

  const headers = parseCSVLine(headerLine);

  const skipIdx = headers.indexOf('skip');

  return rows
    .map((row) => {
      const values = parseCSVLine(row);
      if (!values[0] || values[0].trim() === "") return null;
      if (skipIdx !== -1 && String(values[skipIdx] ?? "").trim() === "1") return null;
      return headers.reduce((item, header, index) => {
        const value = values[index] ?? "";
        item[header] = normalizeValue(value);
        return item;
      }, {});
    })
    .filter(Boolean);
}

function parseCSVLine(line) {
  const values = [];
  let current = "";
  let inQuotes = false;

  for (let index = 0; index < line.length; index += 1) {
    const char = line[index];
    const next = line[index + 1];

    if (char === '"') {
      if (inQuotes && next === '"') {
        current += '"';
        index += 1;
      } else {
        inQuotes = !inQuotes;
      }
      continue;
    }

    if (char === "," && !inQuotes) {
      values.push(current);
      current = "";
      continue;
    }

    current += char;
  }

  values.push(current);
  return values;
}

function normalizeValue(value) {
  const trimmed = value.trim();
  if (trimmed === "") {
    return "";
  }

  const numeric = Number(trimmed);
  return Number.isNaN(numeric) ? trimmed : numeric;
}
