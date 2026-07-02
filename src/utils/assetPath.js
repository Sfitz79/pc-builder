const BASE = import.meta.env.BASE_URL || "./";

export function assetPath(path) {
  if (path.startsWith("http") || path.startsWith("data:")) return path;
  const clean = path.startsWith("/") ? path.slice(1) : path;
  return `${BASE}${clean}`;
}
