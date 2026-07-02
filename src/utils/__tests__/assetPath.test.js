import { describe, it, expect } from "vitest";
import { assetPath } from "../assetPath";

describe("assetPath", () => {
  it("returns http URLs unchanged", () => {
    expect(assetPath("http://example.com/img.jpg")).toBe("http://example.com/img.jpg");
    expect(assetPath("https://example.com/img.jpg")).toBe("https://example.com/img.jpg");
  });

  it("returns data URLs unchanged", () => {
    expect(assetPath("data:image/png;base64,abc123")).toBe("data:image/png;base64,abc123");
  });

  it("prepends base path for relative paths", () => {
    const result = assetPath("thumbnails/img.jpg");
    expect(result).toContain("thumbnails/img.jpg");
  });

  it("handles leading slash", () => {
    const result = assetPath("/images/img.jpg");
    expect(result).toContain("images/img.jpg");
  });
});
