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
      fans: "Noctua NF-A12x25",
      hasRGB: true,
    });

    expect(prompt).toContain("NZXT H5 Flow");
    expect(prompt).toContain("RTX 4080");
    expect(prompt).toContain("Ryzen 7 7800X3D");
    expect(prompt).toContain("RGB LED lighting");
    expect(prompt).toContain("photorealistic gaming PC build");
  });

  it("uses stealth black aesthetic when no RGB", () => {
    const prompt = buildPCImagePrompt({
      case: "Fractal Design North",
      hasRGB: false,
    });

    expect(prompt).toContain("stealth black aesthetic");
  });

  it("handles empty parts gracefully", () => {
    const prompt = buildPCImagePrompt({});
    expect(prompt).toContain("photorealistic gaming PC build");
  });
});

describe("buildPartsFromSelections", () => {
  it("extracts parts from selections object", () => {
    const parts = buildPartsFromSelections({
      case: { name: "NZXT H5 Flow", rgb: "No" },
      gpu: { name: "RTX 4080", rgb: "No" },
      cpu: { name: "Ryzen 7 7800X3D" },
      cooler: { name: "NZXT Kraken X63" },
      ram: { name: "Corsair Vengeance DDR5", rgb: "Yes" },
      motherboard: { name: "ASUS ROG X670E-F" },
      psu: { name: "Corsair RM850x" },
      storage: { name: "Samsung 990 Pro" },
    });

    expect(parts.case).toBe("NZXT H5 Flow");
    expect(parts.gpu).toBe("RTX 4080");
    expect(parts.hasRGB).toBe(true);
  });

  it("detects no RGB when no items have it", () => {
    const parts = buildPartsFromSelections({
      case: { name: "Fractal Design North", rgb: "No" },
      cpu: { name: "Ryzen 7 7800X3D" },
    });

    expect(parts.hasRGB).toBe(false);
  });
});
