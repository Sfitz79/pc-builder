const STORAGE_KEY = "pctg-build-history";

export function getBuildHistory() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch {
    return [];
  }
}

export function saveBuildToHistory(name, selections, description = "") {
  const history = getBuildHistory();
  const build = {
    id: Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
    name: name || `Build ${history.length + 1}`,
    description,
    selections: { ...selections },
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  };
  history.unshift(build);
  localStorage.setItem(STORAGE_KEY, JSON.stringify(history.slice(0, 50)));
  return build;
}

export function updateBuildInHistory(id, selections, name, description) {
  const history = getBuildHistory();
  const idx = history.findIndex(b => b.id === id);
  if (idx === -1) return null;
  if (name) history[idx].name = name;
  if (description !== undefined) history[idx].description = description;
  history[idx].selections = { ...selections };
  history[idx].updatedAt = new Date().toISOString();
  localStorage.setItem(STORAGE_KEY, JSON.stringify(history));
  return history[idx];
}

export function deleteBuildFromHistory(id) {
  const history = getBuildHistory().filter(b => b.id !== id);
  localStorage.setItem(STORAGE_KEY, JSON.stringify(history));
  return history;
}

export function getBuildById(id) {
  return getBuildHistory().find(b => b.id === id) || null;
}

export function clearBuildHistory() {
  localStorage.removeItem(STORAGE_KEY);
}
