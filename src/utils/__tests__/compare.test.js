import { describe, it, expect } from "vitest";
import { compareComponents } from "../compare";

describe("compareComponents", () => {
  it("returns null for less than 2 items", () => {
    expect(compareComponents([], "cpu")).toBeNull();
    expect(compareComponents([{ name: "A" }], "cpu")).toBeNull();
  });

  it("compares two CPUs", () => {
    const result = compareComponents([
      { name: "Ryzen 5 5600X", core_count: "6", boost_clock: "4.6", threads: "12", tdp: "65", socket: "AM4" },
      { name: "Ryzen 7 5800X", core_count: "8", boost_clock: "4.7", threads: "16", tdp: "105", socket: "AM4" },
    ], "cpu");

    expect(result).not.toBeNull();
    expect(result.items).toHaveLength(2);
    expect(result.keys).toContain("core_count");
    expect(result.keys).toContain("boost_clock");
    expect(result.bestValues).toHaveProperty("core_count");
    expect(result.bestValues).toHaveProperty("boost_clock");
  });

  it("compares two GPUs", () => {
    const result = compareComponents([
      { name: "RTX 4070", memory: "12", boost_clock: "2500", tdp: "200" },
      { name: "RTX 4080", memory: "16", boost_clock: "2600", tdp: "320" },
    ], "gpu");

    expect(result).not.toBeNull();
    expect(result.items).toHaveLength(2);
    expect(result.keys).toContain("memory");
    expect(result.keys).toContain("boost_clock");
  });

  it("compares RAM modules", () => {
    const result = compareComponents([
      { name: "Corsair Vengeance DDR5", speed: "6000", modules: "32", cas_latency: "36" },
      { name: "G.Skill Trident Z5", speed: "6400", modules: "32", cas_latency: "32" },
    ], "ram");

    expect(result).not.toBeNull();
    expect(result.items).toHaveLength(2);
    expect(result.bestValues.speed).toBe(6400);
    expect(result.bestValues.cas_latency).toBe(32);
  });
});
