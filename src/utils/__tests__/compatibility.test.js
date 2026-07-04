import { describe, it, expect } from "vitest";
import { checkCompatibility, isOptionCompatible, estimateRequiredWattage } from "../compatibility";

describe("checkCompatibility", () => {
  it("returns issues for missing required components", () => {
    const issues = checkCompatibility({});
    expect(issues.length).toBeGreaterThan(0);
    expect(issues.some(i => i.includes("Cpu is required"))).toBe(true);
    expect(issues.some(i => i.includes("Motherboard is required"))).toBe(true);
  });

  it("returns GPU required when CPU has no iGPU", () => {
    const issues = checkCompatibility({
      cpu: { name: "Intel Core i5-13600K", integrated_graphics: "none" },
    });
    expect(issues.some(i => i.includes("GPU is required"))).toBe(true);
  });

  it("does not require GPU when CPU has integrated graphics", () => {
    const issues = checkCompatibility({
      cpu: { name: "AMD Ryzen 7 8700G", integrated_graphics: "Radeon 780M" },
    });
    expect(issues.some(i => i.includes("GPU is required"))).toBe(false);
  });

  it("detects Windows 11 incompatible CPU", () => {
    const issues = checkCompatibility({
      cpu: { name: "Intel Core i7-950", microarchitecture: "Nehalem" },
      motherboard: { name: "ASUS ROG B550-F", socket: "AM4" },
      ram: { name: "Corsair Vengeance DDR5", speed: "6000", modules: "2x16" },
      case: { name: "NZXT H5 Flow", type: "Mid Tower" },
      cooler: { name: "Noctua NH-D15", size: "158" },
      psu: { name: "Corsair RM750x", wattage: "750" },
      gpu: { name: "RTX 4070", memory: "12", tdp: "200" },
      storage: { name: "Samsung 980 Pro", capacity: "1000", type: "NVMe" },
      os: { name: "Windows 11" },
    });
    expect(issues.some(i => i.includes("Windows 11"))).toBe(true);
  });

  it("detects CPU/motherboard socket mismatch", () => {
    const issues = checkCompatibility({
      cpu: { name: "AMD Ryzen 7 9800X3D", socket: "AM5" },
      motherboard: { name: "ASUS ROG B550-F", socket: "AM4" },
      ram: { name: "Corsair Vengeance DDR5", speed: "6000", modules: "2x16" },
      case: { name: "NZXT H5 Flow", type: "Mid Tower" },
      cooler: { name: "Noctua NH-D15", size: "158" },
      psu: { name: "Corsair RM750x", wattage: "750" },
      gpu: { name: "RTX 4070", memory: "12", tdp: "200" },
      storage: { name: "Samsung 980 Pro", capacity: "1000", type: "NVMe" },
      os: { name: "Windows 11" },
    });
    expect(issues.some(i => i.includes("socket"))).toBe(true);
  });

  it("detects RAM/motherboard generation mismatch", () => {
    const issues = checkCompatibility({
      cpu: { name: "AMD Ryzen 7 9800X3D", socket: "AM5" },
      motherboard: { name: "ASUS ROG X670E-F", socket: "AM5", ram_type: "DDR5", max_memory: "128" },
      ram: { name: "Corsair Vengeance DDR4", speed: "3600", modules: "2x16" },
      case: { name: "NZXT H5 Flow", type: "Mid Tower" },
      cooler: { name: "Noctua NH-D15", size: "158" },
      psu: { name: "Corsair RM750x", wattage: "750" },
      gpu: { name: "RTX 4070", memory: "12", tdp: "200" },
      storage: { name: "Samsung 980 Pro", capacity: "1000", type: "NVMe" },
      os: { name: "Windows 11" },
    });
    expect(issues.some(i => i.includes("RAM type"))).toBe(true);
  });

  it("reports no issues for a compatible build", () => {
    const issues = checkCompatibility({
      cpu: { name: "AMD Ryzen 7 7800X3D", socket: "AM5", core_count: "8", tdp: "120", integrated_graphics: "Radeon Graphics" },
      motherboard: { name: "ASUS ROG X670E-F", socket: "AM5", ram_type: "DDR5", form_factor: "ATX", max_memory: "128" },
      ram: { name: "G.Skill Trident Z5 DDR5", speed: "6000", modules: "2x16", ram_type: "DDR5" },
      cooler: { name: "NZXT Kraken X63", size: "280" },
      storage: { name: "Samsung 990 Pro", capacity: "2000", type: "NVMe" },
      gpu: { name: "RTX 4080", memory: "16", tdp: "320" },
      case: { name: "Lian Li O11 Dynamic", type: "Mid Tower" },
      psu: { name: "Corsair RM850x", wattage: "850" },
      os: { name: "Windows 11" },
      "case-fan": { name: "Noctua NF-A12x25", size: "120" },
    });
    expect(issues.length).toBe(0);
  });
});

describe("isOptionCompatible", () => {
  it("checks CPU compatibility with motherboard", () => {
    expect(isOptionCompatible("cpu", { socket: "AM5" }, { motherboard: { socket: "AM5" } })).toBe(true);
    expect(isOptionCompatible("cpu", { socket: "AM5" }, { motherboard: { socket: "AM4" } })).toBe(false);
  });

  it("checks motherboard compatibility", () => {
    expect(isOptionCompatible("motherboard", { socket: "AM5", ram_type: "DDR5", form_factor: "ATX" }, { cpu: { socket: "AM5" }, ram: { ram_type: "DDR5" }, case: { type: "ATX Mid Tower" } })).toBe(true);
  });

  it("checks RAM compatibility", () => {
    expect(isOptionCompatible("ram", { ram_type: "DDR5" }, { motherboard: { ram_type: "DDR5" } })).toBe(true);
  });

  it("checks GPU compatibility with PSU", () => {
    expect(isOptionCompatible("gpu", { tdp: "300", memory: "12" }, { psu: { wattage: "750" }, cpu: { tdp: "125" } })).toBe(true);
  });

  it("checks PSU compatibility", () => {
    expect(isOptionCompatible("psu", { wattage: "850" }, { gpu: { tdp: "320", memory: "16" }, cpu: { tdp: "125" } })).toBe(true);
    expect(isOptionCompatible("psu", { wattage: "450" }, { gpu: { tdp: "320", memory: "16" }, cpu: { tdp: "125" } })).toBe(false);
  });

  it("checks case compatibility", () => {
    expect(isOptionCompatible("case", { type: "ATX Mid Tower" }, { motherboard: { form_factor: "ATX" } })).toBe(true);
  });

  it("defaults to true for unknown categories", () => {
    expect(isOptionCompatible("unknown", {}, {})).toBe(true);
  });
});

describe("estimateRequiredWattage", () => {
  it("estimates wattage for given CPU and GPU", () => {
    const wattage = estimateRequiredWattage({ tdp: "125" }, { tdp: "300" });
    expect(wattage).toBe(125 + 300 + 150);
  });

  it("uses default TDP for missing CPU", () => {
    const wattage = estimateRequiredWattage(null, { tdp: "300" });
    expect(wattage).toBe(65 + 300 + 150);
  });

  it("returns 0 for missing GPU", () => {
    expect(estimateRequiredWattage({ tdp: "125" }, null)).toBe(0);
  });
});
