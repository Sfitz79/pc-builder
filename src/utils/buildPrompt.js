export function buildPCImagePrompt(parts) {
  const caseName = parts.case || '';
  const gpuName = parts.gpu || '';
  const coolerName = parts.cooler || '';
  const motherboardName = parts.motherboard || '';
  const ramName = parts.ram || '';
  const cpuName = parts.cpu || '';
  const fansName = parts.fans || '';
  const hasRGB = parts.hasRGB || false;
  
  const lighting = hasRGB ? 'RGB LED lighting with vibrant neon glow effects, ' : 'stealth black aesthetic with subtle ambient lighting, ';
  
  const partsList = [
    caseName && `Case: ${caseName}`,
    gpuName && `GPU: ${gpuName}`,
    cpuName && `CPU: ${cpuName}`,
    coolerName && `Cooler: ${coolerName}`,
    motherboardName && `Motherboard: ${motherboardName}`,
    ramName && `RAM: ${ramName}`,
    fansName && `Case Fans: ${fansName}`
  ].filter(Boolean).join(', ');
  
  return `create the fully finished build pc using real parts and only the parts listed: ${partsList}. ${lighting}photorealistic gaming PC build, professional product photography, studio lighting, clean background, high detail`.trim();
}

export function buildPartsFromSelections(selections) {
  const isRGB = (item) => item?.rgb === "Yes";
  const hasAnyRGB = Object.values(selections).some(item => isRGB(item));
  
  return {
    case: selections['case']?.name || '',
    gpu: selections['gpu']?.name || '',
    cpu: selections['cpu']?.name || '',
    cooler: selections['cooler']?.name || '',
    ram: selections['ram']?.name || '',
    fans: selections['case-fan']?.name || '',
    motherboard: selections['motherboard']?.name || '',
    psu: selections['psu']?.name || '',
    storage: selections['storage']?.name || '',
    hasRGB: hasAnyRGB
  };
}