import { describe, it, expect, beforeEach, vi } from "vitest";
import {
  getBuildHistory,
  saveBuildToHistory,
  updateBuildInHistory,
  deleteBuildFromHistory,
  getBuildById,
  clearBuildHistory,
} from "../buildHistory";

beforeEach(() => {
  localStorage.clear();
});

describe("buildHistory", () => {
  it("returns empty array when no history exists", () => {
    expect(getBuildHistory()).toEqual([]);
  });

  it("saves a build to history", () => {
    const build = saveBuildToHistory("My Build", { cpu: "Ryzen 7" }, "Test build");
    expect(build).toHaveProperty("id");
    expect(build).toHaveProperty("name", "My Build");
    expect(build).toHaveProperty("description", "Test build");
    expect(build).toHaveProperty("selections");
    expect(build).toHaveProperty("createdAt");
    expect(build).toHaveProperty("updatedAt");

    expect(getBuildHistory()).toHaveLength(1);
  });

  it("auto-generates name when not provided", () => {
    const build = saveBuildToHistory("", {});
    expect(build.name).toBe("Build 1");
  });

  it("gets a build by id", () => {
    const build = saveBuildToHistory("My Build", {});
    const found = getBuildById(build.id);
    expect(found).not.toBeNull();
    expect(found.name).toBe("My Build");
  });

  it("returns null for non-existent id", () => {
    expect(getBuildById("nonexistent")).toBeNull();
  });

  it("updates a build in history", () => {
    const build = saveBuildToHistory("Original Name", { cpu: "Old" });
    const updated = updateBuildInHistory(build.id, { cpu: "New" }, "Updated Name", "Updated desc");

    expect(updated).not.toBeNull();
    expect(updated.name).toBe("Updated Name");
    expect(updated.description).toBe("Updated desc");
    expect(updated.selections.cpu).toBe("New");
    expect(updated).toHaveProperty("updatedAt");
  });

  it("returns null when updating non-existent build", () => {
    expect(updateBuildInHistory("nonexistent", {})).toBeNull();
  });

  it("deletes a build from history", () => {
    const build = saveBuildToHistory("My Build", {});
    expect(getBuildHistory()).toHaveLength(1);

    deleteBuildFromHistory(build.id);
    expect(getBuildHistory()).toHaveLength(0);
  });

  it("clears all build history", () => {
    saveBuildToHistory("Build 1", {});
    saveBuildToHistory("Build 2", {});
    expect(getBuildHistory()).toHaveLength(2);

    clearBuildHistory();
    expect(getBuildHistory()).toHaveLength(0);
  });
});
