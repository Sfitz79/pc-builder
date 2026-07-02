import { toNumber } from "./common.js";

export function compareComponents(items, categoryId) {
  if (!items || items.length < 2) return null;

  const keys = getComparisonFields(categoryId, items);
  const results = items.map(item => {
    const row = { name: item.name, id: item.id || item.name, _item: item };
    for (const key of keys) {
      row[key] = formatField(key, item[key]);
    }
    return row;
  });

  const bestValues = {};
  for (const key of keys) {
    const field = getFieldConfig(key);
    if (field && field.best) {
      bestValues[key] = field.best(results.map(r => r._item));
    }
  }

  return { items: results, keys, bestValues };
}

function getComparisonFields(categoryId, items) {
  const defaults = items.length > 0 ? Object.keys(items[0]).filter(k => !["id", "name", "image", "price"].includes(k)) : [];
  const fields = {
    cpu: ["core_count", "core_clock", "boost_clock", "threads", "tdp", "socket", "microarchitecture"],
    gpu: ["memory", "core_clock", "boost_clock", "tdp", "chipset", "memory_type"],
    ram: ["speed", "modules", "cas_latency", "ram_type"],
    motherboard: ["socket", "form_factor", "ram_type", "max_memory"],
    storage: ["capacity", "type", "read_speed", "write_speed"],
    psu: ["wattage", "modular", "rating"],
    cooler: ["type", "size", "socket"],
    case: ["type", "form_factor", "max_gpu_length"],
  };
  return fields[categoryId] || defaults;
}

function getFieldConfig(key) {
  const isBetter = (comparator) => ({ best: (items) => {
    const vals = items.map(i => toNumber(i[key]));
    return comparator === "higher" ? Math.max(...vals) : Math.min(...vals);
  }});

  const configs = {
    core_count: isBetter("higher"),
    boost_clock: isBetter("higher"),
    core_clock: isBetter("higher"),
    memory: isBetter("higher"),
    threads: isBetter("higher"),
    tdp: isBetter("lower"),
    wattage: isBetter("higher"),
    speed: isBetter("higher"),
    capacity: isBetter("higher"),
    cas_latency: isBetter("lower"),
    modules: isBetter("higher"),
    max_memory: isBetter("higher"),
    read_speed: isBetter("higher"),
    write_speed: isBetter("higher"),
  };
  return configs[key] || null;
}

function formatField(key, value) {
  if (value === undefined || value === null || value === "") return "—";
  if (["core_count", "threads", "modules"].includes(key)) return String(value);
  if (["core_clock", "boost_clock"].includes(key)) return `${value} GHz`;
  if (["tdp", "wattage"].includes(key)) return `${value}W`;
  if (key === "memory") return `${value}GB`;
  if (["speed", "read_speed", "write_speed"].includes(key)) return `${value} MHz`;
  if (key === "capacity") return `${value}GB`;
  if (key === "cas_latency") return `CL${value}`;
  if (key === "size") return `${value}mm`;
  return String(value);
}
