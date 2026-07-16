import { inferCoolerType } from "./common";

export function buildPCImagePrompt(parts) {
  const {
    case: caseName,
    cpu: cpuName,
    cooler: coolerName,
    motherboard: motherboardName,
    ram: ramName,
    gpu: gpuName,
    psu: psuName,
    storage: storageName,
    storage2: storage2Name,
    storage3: storage3Name,
    storage4: storage4Name,
    fans: fansName,
    monitor: monitorName,
    keyboard: keyboardName,
    mouse: mouseName,
    speakers: speakersName,
    headphones: headphonesName,
    webcam: webcamName,
    wirelessCard: wirelessCardName,
    soundCard: soundCardName,
    hasRGB = false,
  } = parts;

  const lighting = hasRGB ? 'RGB LED lighting with vibrant neon glow effects, ' : 'stealth black aesthetic with subtle ambient lighting, ';

  const isAIO = coolerName
    ? inferCoolerType({ name: coolerName }) === "AIO Liquid Cooler"
    : false;

  const coolerDesc = isAIO
    ? 'AIO liquid cooler with tubes, radiator, and pump block visible'
    : 'air tower cooler with heatpipe arrangement and fan visible';

  const partsList = [
    caseName && `Case: ${caseName}`,
    cpuName && `CPU: ${cpuName}`,
    gpuName && `GPU: ${gpuName}`,
    motherboardName && `Motherboard: ${motherboardName}`,
    ramName && `RAM: ${ramName}`,
    coolerName && `Cooler: ${coolerName}`,
    psuName && `PSU: ${psuName}`,
    storageName && `Storage: ${storageName}`,
    storage2Name && `Storage 2: ${storage2Name}`,
    storage3Name && `Storage 3: ${storage3Name}`,
    storage4Name && `Storage 4: ${storage4Name}`,
    fansName && `Case Fans: ${fansName}`,
    monitorName && `Monitor: ${monitorName}`,
    keyboardName && `Keyboard: ${keyboardName}`,
    mouseName && `Mouse: ${mouseName}`,
    speakersName && `Speakers: ${speakersName}`,
    headphonesName && `Headphones: ${headphonesName}`,
    webcamName && `Webcam: ${webcamName}`,
    wirelessCardName && `WiFi Card: ${wirelessCardName}`,
    soundCardName && `Sound Card: ${soundCardName}`,
  ].filter(Boolean).join(', ');

  return `create the fully finished build pc using real parts and only the parts listed: ${partsList}. ${coolerDesc}. ${lighting}photorealistic gaming PC build, professional product photography, studio lighting, clean background, high detail`.trim();
}

export function buildPartsFromSelections(selections) {
  const isRGB = (item) => item?.rgb === "Yes";
  const hasAnyRGB = Object.values(selections).some(item => isRGB(item));
  
  return {
    case: selections['case']?.name || '',
    cpu: selections['cpu']?.name || '',
    cooler: selections['cooler']?.name || '',
    motherboard: selections['motherboard']?.name || '',
    ram: selections['ram']?.name || '',
    gpu: selections['gpu']?.name || '',
    psu: selections['psu']?.name || '',
    storage: selections['storage']?.name || '',
    storage2: selections['storage2']?.name || '',
    storage3: selections['storage3']?.name || '',
    storage4: selections['storage4']?.name || '',
    fans: selections['case-fan']?.name || '',
    monitor: selections['monitor']?.name || '',
    keyboard: selections['keyboard']?.name || '',
    mouse: selections['mouse']?.name || '',
    speakers: selections['speakers']?.name || '',
    headphones: selections['headphones']?.name || '',
    webcam: selections['webcam']?.name || '',
    wirelessCard: selections['wireless-network-card']?.name || '',
    soundCard: selections['sound-card']?.name || '',
    hasRGB: hasAnyRGB
  };
}