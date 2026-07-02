import { describe, it, expect, vi } from "vitest";
import { render } from "@testing-library/react";
import App from "../App";

vi.mock("../router", () => ({
  default: {
    routes: [],
  },
}));

describe("App", () => {
  it("renders without crashing", () => {
    const { container } = render(<App />);
    expect(container).toBeDefined();
  });
});
