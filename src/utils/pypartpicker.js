export async function searchPCPartPicker(query) {
  if (window.pypartpicker) {
    try {
      const response = await window.pypartpicker.search(query);
      if (response.error) {
        console.error("PCPartPicker search error:", response.error);
        return [];
      }
      return response.parts || [];
    } catch (e) {
      console.error("Failed to call PCPartPicker bridge:", e);
      return [];
    }
  } else {
    console.warn("PCPartPicker bridge not available (non-Electron environment?)");
    return [];
  }
}
