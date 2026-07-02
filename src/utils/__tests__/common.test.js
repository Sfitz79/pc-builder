import { describe, it, expect } from "vitest";
import {
  toNumber,
  normalizeToken,
  inferCpuSocket,
  inferCoolerType,
  isRGB,
  getBrand,
  getBrandLogo,
  getBrandFaviconUrl,
  getBrandPlaceholder,
  getItemImageUrl,
  getItemImageUrls,
  isModernComponent,
  inferChipset,
} from "../common";

describe("toNumber", () => {
  it("returns the number if already a number", () => {
    expect(toNumber(42)).toBe(42);
    expect(toNumber(0)).toBe(0);
    expect(toNumber(-5)).toBe(-5);
  });

  it("parses numeric strings", () => {
    expect(toNumber("123")).toBe(123);
    expect(toNumber("3.14")).toBe(3.14);
    expect(toNumber("0")).toBe(0);
  });

  it("handles strings with commas", () => {
    expect(toNumber("1,234")).toBe(1234);
    expect(toNumber("1,234.56")).toBe(1234.56);
  });

  it("extracts first number from mixed strings", () => {
    expect(toNumber("DDR5-4800")).toBe(5);
    expect(toNumber("abc123def")).toBe(123);
  });

  it("returns 0 for non-string non-number", () => {
    expect(toNumber(null)).toBe(0);
    expect(toNumber(undefined)).toBe(0);
    expect(toNumber({})).toBe(0);
    expect(toNumber("")).toBe(0);
  });

  it("handles negative numbers", () => {
    expect(toNumber("-42")).toBe(-42);
  });
});

describe("normalizeToken", () => {
  it("trims and uppercases", () => {
    expect(normalizeToken("  am5  ")).toBe("AM5");
    expect(normalizeToken("lga1700")).toBe("LGA1700");
  });

  it("returns null for empty-ish values", () => {
    expect(normalizeToken(null)).toBeNull();
    expect(normalizeToken(undefined)).toBeNull();
    expect(normalizeToken("")).toBeNull();
  });
});

describe("inferCpuSocket", () => {
  it("returns direct socket field", () => {
    expect(inferCpuSocket({ socket: "AM5" })).toBe("AM5");
    expect(inferCpuSocket({ socket: "LGA1700" })).toBe("LGA1700");
  });

  it("infers AMD socket from microarchitecture", () => {
    expect(inferCpuSocket({ name: "Ryzen 7 9800X3D", microarchitecture: "Zen 4" })).toBe("AM5");
    expect(inferCpuSocket({ name: "Ryzen 7 9800X3D", microarchitecture: "Zen 5" })).toBe("AM5");
    expect(inferCpuSocket({ name: "Ryzen 7 5800X3D", microarchitecture: "Zen 3" })).toBe("AM4");
    expect(inferCpuSocket({ name: "Ryzen 5 5600X", microarchitecture: "Zen 3" })).toBe("AM4");
    expect(inferCpuSocket({ name: "Ryzen 5 2600", microarchitecture: "Zen+" })).toBe("AM4");
    expect(inferCpuSocket({ name: "Ryzen 5 1600", microarchitecture: "Zen" })).toBe("AM4");
  });

  it("infers Intel socket from microarchitecture", () => {
    expect(inferCpuSocket({ name: "Intel Core Ultra 9 285K", microarchitecture: "Arrow Lake" })).toBe("LGA1851");
    expect(inferCpuSocket({ name: "Intel Core i9-14900K", microarchitecture: "Raptor Lake" })).toBe("LGA1700");
    expect(inferCpuSocket({ name: "Intel Core i9-13900K", microarchitecture: "Raptor Lake" })).toBe("LGA1700");
    expect(inferCpuSocket({ name: "Intel Core i7-12700K", microarchitecture: "Alder Lake" })).toBe("LGA1700");
    expect(inferCpuSocket({ name: "Intel Core i7-8700K", microarchitecture: "Coffee Lake" })).toBe("LGA1151");
    expect(inferCpuSocket({ name: "Intel Core i7-6700K", microarchitecture: "Skylake" })).toBe("LGA1151");
    expect(inferCpuSocket({ name: "Intel Core i7-4790K", microarchitecture: "Haswell" })).toBe("LGA1150");
    expect(inferCpuSocket({ name: "Intel Core i7-2600K", microarchitecture: "Sandy Bridge" })).toBe("LGA1155");
  });

  it("returns null for unknown CPUs", () => {
    expect(inferCpuSocket({ name: "Unknown CPU" })).toBeNull();
  });
});

describe("inferCoolerType", () => {
  it("detects AIO liquid coolers", () => {
    expect(inferCoolerType({ name: "NZXT Kraken X63" })).toBe("AIO Liquid Cooler");
    expect(inferCoolerType({ name: "Corsair H150i Elite V3" })).toBe("AIO Liquid Cooler");
    expect(inferCoolerType({ name: "Deepcool LS720 AIO" })).toBe("AIO Liquid Cooler");
    expect(inferCoolerType({ name: "Cooler Master MasterLiquid ML360R" })).toBe("AIO Liquid Cooler");
    expect(inferCoolerType({ name: "ARCTIC Liquid Freezer III 360" })).toBe("AIO Liquid Cooler");
  });

  it("detects air coolers", () => {
    expect(inferCoolerType({ name: "Noctua NH-D15" })).toBe("Air Cooler");
    expect(inferCoolerType({ name: "be quiet! Dark Rock Pro 5" })).toBe("Air Cooler");
    expect(inferCoolerType({ name: "Cooler Master Hyper 212" })).toBe("Air Cooler");
  });

  it("handles missing name", () => {
    expect(inferCoolerType({})).toBe("Air Cooler");
    expect(inferCoolerType(null)).toBe("Air Cooler");
  });
});

describe("isRGB", () => {
  it("detects RGB in item", () => {
    expect(isRGB({ name: "Corsair Vengeance RGB" })).toBe(true);
    expect(isRGB({ rgb: "Yes" })).toBe(true);
    expect(isRGB({ name: "Some ARGB fans" })).toBe(true);
  });

  it("returns false for non-RGB items", () => {
    expect(isRGB({ name: "Noctua NH-D15" })).toBe(false);
    expect(isRGB(null)).toBe(false);
    expect(isRGB({})).toBe(false);
  });
});

describe("getBrand", () => {
  it("returns brand from brand field", () => {
    expect(getBrand({ brand: "NVIDIA" })).toBe("NVIDIA");
    expect(getBrand({ brand: "AMD" })).toBe("AMD");
  });

  it("returns brand from manufacturer field", () => {
    expect(getBrand({ manufacturer: "Intel" })).toBe("INTEL");
  });

  it("infers brand from name first word", () => {
    expect(getBrand({ name: "Corsair Vengeance LPX" })).toBe("CORSAIR");
    expect(getBrand({ name: "Noctua NH-D15" })).toBe("NOCTUA");
  });

  it("returns UNKNOWN for missing info", () => {
    expect(getBrand({})).toBe("UNKNOWN");
    expect(getBrand(null)).toBe("UNKNOWN");
  });
});

describe("getBrandLogo", () => {
  it("returns clearbit URL for known brands", () => {
    const url = getBrandLogo({ brand: "Corsair" });
    expect(url).toContain("logo.clearbit.com");
    expect(url).toContain("corsair.com");
  });

  it("returns a URL even for unknown brands (falls back to domain generation)", () => {
    expect(getBrandLogo({})).toContain("logo.clearbit.com");
  });
});

describe("getBrandFaviconUrl", () => {
  it("returns Google favicon URL for known brands", () => {
    const url = getBrandFaviconUrl({ brand: "NVIDIA" });
    expect(url).toContain("google.com/s2/favicons");
    expect(url).toContain("nvidia.com");
  });

  it("returns a URL even for null (falls back to domain generation)", () => {
    expect(getBrandFaviconUrl(null)).toContain("google.com/s2/favicons");
  });
});

describe("getBrandPlaceholder", () => {
  it("returns placeholder URL with brand initials", () => {
    const url = getBrandPlaceholder({ brand: "Corsair" });
    expect(url).toContain("placehold.co");
    expect(url).toContain("CORSA");
  });
});

describe("getItemImageUrl", () => {
  it("returns first image URL", () => {
    expect(getItemImageUrl({ image: "http://example.com/img.jpg" })).toBe("http://example.com/img.jpg");
  });

  it("prefixes with / for relative paths", () => {
    expect(getItemImageUrl({ image: "thumbnails/img.jpg" })).toBe("/thumbnails/img.jpg");
    expect(getItemImageUrl({ image: "/images/img.jpg" })).toBe("/images/img.jpg");
  });

  it("returns null for no image", () => {
    expect(getItemImageUrl({})).toBeNull();
    expect(getItemImageUrl(null)).toBeNull();
  });
});

describe("getItemImageUrls", () => {
  it("returns array of image urls", () => {
    const urls = getItemImageUrls({ image: "http://a.jpg,http://b.jpg" });
    expect(urls).toHaveLength(2);
  });

  it("returns empty array for no images", () => {
    expect(getItemImageUrls({})).toEqual([]);
    expect(getItemImageUrls(null)).toEqual([]);
  });
});

describe("isModernComponent", () => {
  it("returns true for modern CPU", () => {
    expect(isModernComponent("cpu", { socket: "AM5" })).toBe(true);
    expect(isModernComponent("cpu", { socket: "LGA1700" })).toBe(true);
  });

  it("returns false for old CPU", () => {
    expect(isModernComponent("cpu", { socket: "LGA775" })).toBe(false);
    expect(isModernComponent("cpu", { name: "Ryzen 3 3100", microarchitecture: "Zen 2" })).toBe(false);
  });

  it("returns false for old motherboard socket", () => {
    expect(isModernComponent("motherboard", { socket: "LGA1150" })).toBe(false);
  });

  it("returns true for modern motherboard", () => {
    expect(isModernComponent("motherboard", { socket: "AM5" })).toBe(true);
    expect(isModernComponent("motherboard", { socket: "LGA1700" })).toBe(true);
  });

  it("returns false for old DDR3 RAM", () => {
    expect(isModernComponent("ram", { ram_type: "DDR3" })).toBe(false);
  });

  it("returns true for DDR5 RAM", () => {
    expect(isModernComponent("ram", { ram_type: "DDR5" })).toBe(true);
  });

  it("returns false for low wattage PSU", () => {
    expect(isModernComponent("psu", { wattage: "400" })).toBe(false);
  });

  it("returns true for modern PSU", () => {
    expect(isModernComponent("psu", { wattage: "750" })).toBe(true);
  });

  it("returns true for modern GPU", () => {
    expect(isModernComponent("gpu", { name: "RTX 4070", memory: "12" })).toBe(true);
  });

  it("returns false for old GPU", () => {
    expect(isModernComponent("gpu", { name: "GTX 980", memory: "4" })).toBe(false);
  });

  it("returns true for other categories", () => {
    expect(isModernComponent("case", {})).toBe(true);
  });
});

describe("inferChipset", () => {
  it("extracts chipset from name", () => {
    expect(inferChipset("B650")).toBe("B650");
    expect(inferChipset("X870E")).toBe("X870E");
    expect(inferChipset("Z790")).toBe("Z790");
  });

  it("returns null for no match", () => {
    expect(inferChipset("")).toBeNull();
    expect(inferChipset(null)).toBeNull();
  });
});
