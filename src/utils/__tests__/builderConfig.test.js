import { describe, it, expect } from "vitest";
import {
  BUILDER_CATEGORIES,
  SUBCATEGORY_GROUPS,
  getComponentIconSVG,
} from "../builderConfig";

describe("BUILDER_CATEGORIES", () => {
  it("contains all main PC part categories", () => {
    const ids = BUILDER_CATEGORIES.map(c => c.id);
    expect(ids).toContain("cpu");
    expect(ids).toContain("motherboard");
    expect(ids).toContain("cooler");
    expect(ids).toContain("ram");
    expect(ids).toContain("ssd");
    expect(ids).toContain("mass-storage");
    expect(ids).toContain("gpu");
    expect(ids).toContain("case");
    expect(ids).toContain("psu");
    expect(ids).toContain("case-fan");
    expect(ids).toContain("monitor");
  });

  it("has required fields for each category", () => {
    for (const cat of BUILDER_CATEGORIES) {
      expect(cat).toHaveProperty("id");
      expect(cat).toHaveProperty("label");
      expect(cat).toHaveProperty("file");
      expect(cat).toHaveProperty("icon");
    }
  });
});

describe("SUBCATEGORY_GROUPS", () => {
  it("contains expansion cards, peripherals, and accessories", () => {
    const labels = SUBCATEGORY_GROUPS.map(g => g.label);
    expect(labels).toContain("Expansion Cards / Networking");
    expect(labels).toContain("Peripherals");
    expect(labels).toContain("Thermal & Accessories");
  });
});

describe("getComponentIconSVG", () => {
  it("returns an SVG data URI for a known icon", () => {
    const svg = getComponentIconSVG("cpu");
    expect(svg).toContain("data:image/svg+xml");
    expect(svg).toContain("<svg");
    expect(svg).toContain("viewBox");
  });

  it("returns fallback icon for unknown names", () => {
    const svg = getComponentIconSVG("nonexistent");
    expect(svg).toContain("data:image/svg+xml");
  });
});
