import { describe, it, expect, beforeEach } from "vitest";
import { act } from "@testing-library/react";
import { usePCStore } from "../usePCStore";

const DEFAULT_OS = {
  name: "Microsoft Windows 11 Pro Retail - Download 64-bit",
  price: "189.99",
  mode: "64",
  max_memory: "2048"
};

beforeEach(() => {
  localStorage.clear();
  usePCStore.setState({
    selections: { os: DEFAULT_OS },
    adminMode: false,
    buildType: "full",
    onBuildComplete: null,
    mandatory: {
      build_service: { name: "PCTG Build, Premium Cable Manage, Premium Test & Optimize", price: 150 },
      warranty: { name: "2 Year Warranty & Free Tech Support / Remote Assistance", price: 0 },
      delivery: { name: "Delivery By RM Special Delivery Insured", price: 50 },
      os: { name: "Windows 11 Pro Retail", price: 35 },
    },
  });
});

describe("usePCStore", () => {
  it("initializes with default OS and full build type", () => {
    const state = usePCStore.getState();
    expect(state.selections.os).toBeDefined();
    expect(state.selections.os.name).toContain("Windows 11");
    expect(state.buildType).toBe("full");
    expect(state.adminMode).toBe(false);
  });

  it("setAdminMode updates admin mode", () => {
    act(() => usePCStore.getState().setAdminMode(true));
    expect(usePCStore.getState().adminMode).toBe(true);
  });

  it("setBuildType changes build type and mandatory services", () => {
    act(() => usePCStore.getState().setBuildType("parts"));
    const state = usePCStore.getState();
    expect(state.buildType).toBe("parts");
    expect(state.mandatory).not.toHaveProperty("build_service");
  });

  it("setComponent adds component to selections", () => {
    const cpu = { name: "AMD Ryzen 7 7800X3D", price: "449" };
    act(() => usePCStore.getState().setComponent("cpu", cpu));
    expect(usePCStore.getState().selections.cpu).toEqual(cpu);
  });

  it("clearComponent removes component from selections", () => {
    act(() => usePCStore.getState().setComponent("cpu", { name: "AMD Ryzen 7 7800X3D", price: "449" }));
    expect(usePCStore.getState().selections.cpu).toBeDefined();
    act(() => usePCStore.getState().clearComponent("cpu"));
    expect(usePCStore.getState().selections.cpu).toBeUndefined();
  });

  it("resetBuild clears all selections except OS", () => {
    act(() => usePCStore.getState().setComponent("cpu", { name: "Ryzen 7", price: "449" }));
    act(() => usePCStore.getState().setComponent("gpu", { name: "RTX 4080", price: "999" }));
    act(() => usePCStore.getState().resetBuild());
    const state = usePCStore.getState();
    expect(state.selections.cpu).toBeUndefined();
    expect(state.selections.gpu).toBeUndefined();
    expect(state.selections.os).toBeDefined();
  });

  it("getComponentsTotal sums component prices", () => {
    act(() => usePCStore.getState().setComponent("cpu", { name: "Ryzen 7", price: "449.99" }));
    act(() => usePCStore.getState().setComponent("gpu", { name: "RTX 4080", price: "999.99" }));
    const total = usePCStore.getState().getComponentsTotal();
    expect(total).toBe(449.99 + 999.99);
  });

  it("getComponentsTotal excludes OS for full build", () => {
    act(() => usePCStore.getState().setComponent("cpu", { name: "Ryzen 7", price: "449.99" }));
    const total = usePCStore.getState().getComponentsTotal();
    expect(total).toBe(449.99);
  });

  it("getMandatoryTotal sums mandatory service prices", () => {
    const total = usePCStore.getState().getMandatoryTotal();
    expect(total).toBe(150 + 0 + 50 + 35);
  });

  it("getBundledPrice calculates final price with surcharge", () => {
    act(() => usePCStore.getState().setComponent("cpu", { name: "Ryzen 7", price: "200" }));
    const bundled = usePCStore.getState().getBundledPrice();
    expect(bundled).toBe(Math.ceil((200 + 150 + 0 + 50 + 35) * 1.03));
  });

  it("getPriceBreakdown returns full breakdown", () => {
    act(() => usePCStore.getState().setComponent("cpu", { name: "Ryzen 7", price: "449.99" }));
    const breakdown = usePCStore.getState().getPriceBreakdown();
    expect(breakdown).toHaveProperty("items");
    expect(breakdown).toHaveProperty("componentsTotal");
    expect(breakdown).toHaveProperty("mandatoryTotal");
    expect(breakdown).toHaveProperty("subtotal");
    expect(breakdown).toHaveProperty("surcharge");
    expect(breakdown).toHaveProperty("total");
    expect(breakdown.items.length).toBeGreaterThan(0);
  });

  it("setOnBuildComplete stores callback", () => {
    const fn = () => {};
    act(() => usePCStore.getState().setOnBuildComplete(fn));
    expect(usePCStore.getState().onBuildComplete).toBe(fn);
  });
});
