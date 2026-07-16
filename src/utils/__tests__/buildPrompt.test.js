import { describe, it, expect } from "vitest";
import { buildPCImagePrompt, buildPartsFromSelections } from "../buildPrompt";

describe("buildPCImagePrompt", () => {
  it("builds a prompt with all parts", () => {
    const prompt = buildPCImagePrompt({
      case: "NZXT H5 Flow",
      gpu: "RTX 4080",
      cpu: "Ryzen 7 7800X3D",
      cooler: "NZXT Kraken X63",
      motherboard: "ASUS ROG X670E-F",
      ram: "Corsair Vengeance DDR5",
      psu: "Corsair RM850x",
      storage: "Samsung 990 Pro",
      fans: "Noctua NF-A12x25",
      monitor: "LG 27GP950",
      hasRGB: true,
    });

    expect(prompt).toContain("NZXT H5 Flow");
    expect(prompt).toContain("RTX 4080");
    expect(prompt).toContain("Ryzen 7 7800X3D");
    expect(prompt).toContain("Corsair RM850x");
    expect(prompt).toContain("Samsung 990 Pro");
    expect(prompt).toContain("LG 27GP950");
    expect(prompt).toContain("real parts and only the parts listed");
    expect(prompt).toContain("RGB LED lighting");
    expect(prompt).toContain("photorealistic gaming PC build");
    expect(prompt).toContain("AIO liquid cooler");
  });

  it("detects air cooler when no AIO keywords in name", () => {
    const prompt = buildPCImagePrompt({
      case: "Fractal Design North",
      cooler: "Noctua NH-D15",
      hasRGB: false,
    });

    expect(prompt).toContain("air tower cooler");
    expect(prompt).toContain("stealth black aesthetic");
  });

  it("handles empty parts gracefully", () => {
    const prompt = buildPCImagePrompt({});
    expect(prompt).toContain("photorealistic gaming PC build");
  });
});

describe("buildPartsFromSelections", () => {
  it("extracts all parts from selections object", () => {
    const parts = buildPartsFromSelections({
      case: { name: "NZXT H5 Flow", rgb: "No" },
      gpu: { name: "RTX 4080", rgb: "No" },
      cpu: { name: "Ryzen 7 7800X3D" },
      cooler: { name: "NZXT Kraken X63" },
      ram: { name: "Corsair Vengeance DDR5", rgb: "Yes" },
      motherboard: { name: "ASUS ROG X670E-F" },
      psu: { name: "Corsair RM850x" },
      storage: { name: "Samsung 990 Pro" },
      storage2: { name: "WD Black SN850" },
      "case-fan": { name: "Noctua NF-A12x25" },
      monitor: { name: "LG 27GP950" },
      keyboard: { name: "Ducky One 3" },
    });

    expect(parts.case).toBe("NZXT H5 Flow");
    expect(parts.gpu).toBe("RTX 4080");
    expect(parts.psu).toBe("Corsair RM850x");
    expect(parts.storage2).toBe("WD Black SN850");
    expect(parts.fans).toBe("Noctua NF-A12x25");
    expect(parts.monitor).toBe("LG 27GP950");
    expect(parts.keyboard).toBe("Ducky One 3");
    expect(parts.hasRGB).toBe(true);
  });

  it("extracts only selected parts", () => {
    const parts = buildPartsFromSelections({
      case: { name: "NZXT H5 Flow" },
      cpu: { name: "Ryzen 7 7800X3D" },
    });

    expect(parts.case).toBe("NZXT H5 Flow");
    expect(parts.gpu).toBe("");
    expect(parts.psu).toBe("");
    expect(parts.storage).toBe("");
    expect(parts.hasRGB).toBe(false);
  });

  it("detects no RGB when no items have it", () => {
    const parts = buildPartsFromSelections({
      case: { name: "Fractal Design North", rgb: "No" },
      cpu: { name: "Ryzen 7 7800X3D" },
    });

    expect(parts.hasRGB).toBe(false);
  });
});
