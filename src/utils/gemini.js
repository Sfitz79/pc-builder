import { inferCoolerType } from "./common";

const VERCEL_IMG_URL = import.meta.env.VITE_LEONARDO_PROXY_URL || "https://img-gen-pctg.vercel.app/api/generate";
const GEMINI_API_KEY = import.meta.env.VITE_GEMINI_API_KEY || null;
const GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-image-preview:generateContent";

export async function generateBuildMockup(selections) {
  if (!selections['case']?.name) {
    return null;
  }

  try {
    const imageData = await generateWithLeonardo(selections);
    if (imageData) return imageData;
  } catch (error) {
    console.warn("Leonardo failed, trying Gemini:", error);
  }

  try {
    const imageData = await generateWithGemini(selections);
    if (imageData) return imageData;
  } catch (error) {
    console.warn("Gemini failed:", error);
  }

  const fallback = await ensureCaseImage(selections);
  if (fallback) return fallback;

  return null;
}

function getPrimaryImage(item) {
  if (!item?.image) return null;
  const images = String(item.image).split(",").filter(Boolean);
  if (images.length === 0) return null;
  let path = images[0];
  if (path.startsWith("thumbnails/")) return "/" + path;
  if (!path.startsWith("http") && !path.startsWith("/")) return "/thumbnails/" + path;
  return path;
}

function loadImage(src) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = "anonymous";
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error(`Failed to load: ${src}`));
    img.src = src;
  });
}

async function searchProductImage(productName) {
  const retailers = [
    `https://www.scan.co.uk/search?q=${encodeURIComponent(productName)}`,
    `https://www.overclockers.co.uk/search?search=${encodeURIComponent(productName)}`,
    `https://www.ebuyer.com/search?q=${encodeURIComponent(productName)}`,
  ];
  for (const url of retailers) {
    try {
      const ctrl = new AbortController();
      const id = setTimeout(() => ctrl.abort(), 5000);
      const res = await fetch(url, { signal: ctrl.signal });
      clearTimeout(id);
      if (!res.ok) continue;
      const html = await res.text();
      const match = html.match(/<img[^>]+src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*(?:product|item|\d+)[^"]*)"/i);
      if (match && match[1].length > 60) return match[1];
    } catch (e) {}
  }
  return null;
}

async function createPartsCollage(selections) {
  const canvas = document.createElement("canvas");
  canvas.width = 1024;
  canvas.height = 1024;
  const ctx = canvas.getContext("2d");
  ctx.fillStyle = "#0d0d12";
  ctx.fillRect(0, 0, 1024, 1024);

  const parts = [
    { key: "case",       label: "Case",         x: 312, y: 162, w: 400, h: 400 },
    { key: "gpu",        label: "GPU",          x: 40,  y: 620, w: 220, h: 130 },
    { key: "cpu",        label: "CPU",          x: 764, y: 620, w: 220, h: 130 },
    { key: "motherboard",label: "Motherboard",   x: 40,  y: 50,  w: 180, h: 130 },
    { key: "cooler",     label: "Cooler",        x: 804, y: 50,  w: 180, h: 130 },
    { key: "ram",        label: "RAM",           x: 40,  y: 780, w: 220, h: 70  },
    { key: "psu",        label: "PSU",           x: 764, y: 780, w: 220, h: 70  },
  ];

  const optionalParts = [
    { key: "monitor",    label: "Monitor",      x: 40,  y: 880, w: 220, h: 100 },
    { key: "keyboard",   label: "Keyboard",     x: 764, y: 880, w: 220, h: 100 },
  ];

  const allSlots = [...parts, ...optionalParts];

  const loadPromises = allSlots.map(p => {
    let src = getPrimaryImage(selections[p.key]);
    if (src) return loadImage(src).then(img => ({ key: p.key, img })).catch(() => ({ key: p.key, img: null }));
    return Promise.resolve({ key: p.key, img: null });
  });

  const results = await Promise.all(loadPromises);
  const imageMap = {};
  results.forEach(r => { imageMap[r.key] = r.img; });

  for (const p of allSlots) {
    let img = imageMap[p.key];
    if (!img && selections[p.key]?.name) {
      const searched = await searchProductImage(selections[p.key].name);
      if (searched) {
        try { img = await loadImage(searched); } catch (e) {}
      }
    }
    if (img) {
      const aspect = img.width / img.height;
      let dw = p.w, dh = p.h;
      if (aspect > 1) dh = p.w / aspect;
      else dw = p.h * aspect;
      const dx = p.x + (p.w - dw) / 2;
      const dy = p.y + (p.h - dh) / 2;
      ctx.save();
      ctx.beginPath();
      ctx.roundRect(p.x, p.y, p.w, p.h, 6);
      ctx.clip();
      ctx.drawImage(img, dx, dy, dw, dh);
      ctx.restore();
    }
    ctx.strokeStyle = "rgba(99,102,241,0.3)";
    ctx.lineWidth = 1;
    ctx.strokeRect(p.x, p.y, p.w, p.h);

    ctx.fillStyle = "rgba(99,102,241,0.6)";
    ctx.font = "11px monospace";
    ctx.fillText(p.label, p.x + 6, p.y + 14);
  }

  ctx.fillStyle = "rgba(255,255,255,0.05)";
  ctx.fillRect(0, 0, 1024, 1024);
  ctx.strokeStyle = "rgba(99,102,241,0.15)";
  ctx.strokeRect(1, 1, 1022, 1022);

  return canvas.toDataURL("image/png");
}

function buildPartsList(selections) {
  const lines = [
    selections['case']?.name,
    selections['cpu']?.name,
    selections['gpu']?.name,
    selections['motherboard']?.name,
    selections['ram']?.name,
    selections['cooler']?.name,
    selections['psu']?.name,
    selections['ssd']?.name,
    selections['mass-storage']?.name,
    selections['case-fan']?.name,
    selections['monitor']?.name,
    selections['keyboard']?.name,
    selections['mouse']?.name,
    selections['headphones']?.name,
    selections['speakers']?.name,
    selections['webcam']?.name,
    selections['wireless-network-card']?.name,
    selections['sound-card']?.name,
  ].filter(Boolean);
  return lines.map(n => `- ${n}`).join("\n");
}

function buildPrompt(selections) {
  const partsList = buildPartsList(selections);
  const isAIO = selections['cooler']
    ? inferCoolerType(selections['cooler']) === "AIO Liquid Cooler"
    : false;

  const coolerDesc = isAIO
    ? "AIO liquid cooler with tubes, radiator, and pump block design visible"
    : "Air tower cooler with heatpipe arrangement and fan visible";

  return `Generate a photorealistic studio photo of a fully assembled custom gaming PC. Use ONLY the EXACT real products listed below — do not add any components that are not in this list. Each component must look like its real-world counterpart: same shape, colors, branding, and design.

ONLY these components (omit any not listed):
${partsList}

Requirements:
- The CASE must be the exact model listed — same shape, color, panel design, front mesh/glass layout, and dimensions
- GPU must show the correct cooler shroud design, fan count, and backplate
- CPU cooler: ${coolerDesc}
- RAM must show the correct height, heatsink design, and RGB lighting if applicable
- Motherboard must match PCB color, VRM heatsinks, and chipset location
- If a component type is not in the list above, do NOT include it in the image
- Studio lighting, clean dark background, 3/4 angle through side panel
- Photorealistic, high detail, product photography quality`;
}

function arrayBufferToBase64(buffer) {
  const bytes = new Uint8Array(buffer);
  let binary = "";
  const chunk = 8192;
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode(...bytes.subarray(i, i + chunk));
  }
  return btoa(binary);
}

async function loadImageAsBase64(src) {
  try {
    const img = await loadImage(src);
    const canvas = document.createElement("canvas");
    canvas.width = img.width;
    canvas.height = img.height;
    const ctx = canvas.getContext("2d");
    ctx.drawImage(img, 0, 0);
    return canvas.toDataURL("image/png");
  } catch {
    return null;
  }
}

async function ensureCaseImage(selections) {
  let src = getPrimaryImage(selections["case"]);
  if (src) {
    const b64 = await loadImageAsBase64(src);
    if (b64) return b64;
  }
  const name = selections["case"]?.name;
  if (name) {
    const searched = await searchProductImage(name);
    if (searched) return loadImageAsBase64(searched);
  }
  return null;
}

async function generateWithLeonardo(selections) {
  const prompt = buildPrompt(selections);

  let referenceImage = null;
  try {
    referenceImage = await ensureCaseImage(selections);
  } catch (e) {
    console.warn("Could not get case reference image:", e);
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 70000);

  try {
    const response = await fetch(VERCEL_IMG_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      signal: controller.signal,
      body: JSON.stringify({
        prompt,
        provider: "leonardo",
        referenceImage,
      }),
    });

    clearTimeout(timeout);

    if (!response.ok) {
      const err = await response.text();
      throw new Error(`Leonardo error: ${response.status} ${err}`);
    }

    const blob = await response.blob();
    const mimeType = blob.type || "image/png";
    const buffer = await blob.arrayBuffer();
    return `data:${mimeType};base64,${arrayBufferToBase64(buffer)}`;
  } catch (error) {
    clearTimeout(timeout);
    if (error.name === "AbortError") throw new Error("Leonardo timed out after 70s");
    throw error;
  }
}



function buildGeminiPrompt(selections) {
  const pcCase = selections['case']?.name || "unknown case";
  const gpu = selections['gpu']?.name || "";
  const cooler = selections['cooler']?.name || "";
  const ram = selections['ram']?.name || "";
  const cpu = selections['cpu']?.name || "";
  const motherboard = selections['motherboard']?.name || "";
  const psu = selections['psu']?.name || "";
  const storage = selections['ssd']?.name || "";
  const storage2 = selections['mass-storage']?.name || "";
  const caseFan = selections['case-fan']?.name || "";
  const monitor = selections['monitor']?.name || "";
  const keyboard = selections['keyboard']?.name || "";
  const mouse = selections['mouse']?.name || "";
  const headphones = selections['headphones']?.name || "";
  const speakers = selections['speakers']?.name || "";
  const webcam = selections['webcam']?.name || "";
  const wirelessCard = selections['wireless-network-card']?.name || "";
  const soundCard = selections['sound-card']?.name || "";

  const hasRGB = Object.values(selections).some(item =>
    JSON.stringify(item).toLowerCase().includes("rgb")
  );
  const isWhite = pcCase.toLowerCase().includes("white");
  const isAIO = selections['cooler']
    ? inferCoolerType(selections['cooler']) === "AIO Liquid Cooler"
    : false;

  const partsList = [
    [`Case`, pcCase],
    motherboard && [`Motherboard`, motherboard],
    cpu && [`CPU`, cpu],
    gpu && [`GPU`, gpu],
    ram && [`RAM`, ram],
    cooler && [`Cooler`, cooler],
    psu && [`PSU`, psu],
    storage && [`Storage (Drive 1)`, storage],
    storage2 && [`Storage (Drive 2)`, storage2],
    storage3 && [`Storage (Drive 3)`, storage3],
    storage4 && [`Storage (Drive 4)`, storage4],
    caseFan && [`Case Fans`, caseFan],
    monitor && [`Monitor`, monitor],
    keyboard && [`Keyboard`, keyboard],
    mouse && [`Mouse`, mouse],
    headphones && [`Headphones`, headphones],
    speakers && [`Speakers`, speakers],
    webcam && [`Webcam`, webcam],
    wirelessCard && [`WiFi / Network Card`, wirelessCard],
    soundCard && [`Sound Card`, soundCard],
  ].filter(Boolean);

  const partsDetail = partsList.map(([type, name]) =>
    `- ${type}: "${name}" (THIS EXACT PRODUCT — render its real-world design, shape, colors, branding, port/vent layout, and dimensions accurately)`
  ).join("\n");

  return `You have expert knowledge of PC hardware products. Generate a photorealistic image of a fully assembled custom gaming PC that uses ONLY the EXACT products listed below. Do NOT include any component type that is not in this list — if a part slot is not listed, it should not appear in the image.

ONLY these components (render each with its real-world visual design — this is critical):
${partsDetail}

CRITICAL REQUIREMENTS:
- THE CASE IS THE MOST IMPORTANT COMPONENT. It must be the EXACT model listed above — same shape, color, dimensions, front panel design (mesh/glass/solid), side panel window shape, I/O port layout, feet design, and any branding placement
- GPU must show its exact cooler shroud, fan count and design, backplate, and RGB lighting position
- CPU cooler must be rendered accurately: if AIO, show tubes, radiator, and pump block design; if air tower, show heatpipe arrangement and fan clips
- RAM must show the correct heatsink height, fin design, and LED diffuser if RGB
- Motherboard must match PCB color, VRM heatsink shape, chipset cooler, and slot layout
- Show the PC from a 3/4 angle through the tempered glass side panel so internal components are visible
- ${isWhite ? "White PC case with clean aesthetic, white/silver LED lighting" : "Black PC case with dark gaming aesthetic, subtle LED lighting"}
- ${hasRGB ? "RGB lighting glow on RAM, GPU, cooler fans and case" : "Clean minimal lighting, professional look"}
- ${isAIO ? "AIO liquid cooler with tubes connected to CPU block, radiator with fans visible" : "Air tower CPU cooler with heatpipes and fan visible"}
- Neat cable management through the back
- Peripheral devices (monitor, keyboard, mouse, headphones, speakers, webcam) placed on desk around the PC — render their approximate real designs
- Dark studio background, cinematic product photography lighting, 4K detail
- NO text labels, NO logos/badges, NO watermark
- IMPORTANT: The components must be recognizable as the exact products listed — if you render a generic substitute, the image is worthless`;
}

async function generateWithGemini(selections) {
  const prompt = buildGeminiPrompt(selections);

  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 60000);

    const parts = [{ text: prompt }];

    try {
      const collage = await createPartsCollage(selections);
      if (collage) {
        parts.push({
          inlineData: { mimeType: "image/png", data: collage.replace(/^data:image\/\w+;base64,/, "") }
        });
      }
    } catch (e) {
      console.warn("Could not attach collage to Gemini prompt:", e);
    }

    const response = await fetch(`${GEMINI_API_URL}?key=${GEMINI_API_KEY}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      signal: controller.signal,
      body: JSON.stringify({
        contents: [{ parts }],
        generationConfig: {
          responseModalities: ["TEXT", "IMAGE"]
        }
      })
    });

    clearTimeout(timeout);

    if (!response.ok) {
      const errorText = await response.text();
      throw new Error(`Gemini API error: ${response.status} ${errorText}`);
    }

    const data = await response.json();

    if (data.candidates && data.candidates[0]?.content?.parts) {
      for (const part of data.candidates[0].content.parts) {
        if (part.inlineData) {
          return `data:${part.inlineData.mimeType};base64,${part.inlineData.data}`;
        }
      }
    }

    throw new Error("No image data in response");
  } catch (error) {
    if (error.name === "AbortError") {
      throw new Error("Gemini image generation timed out after 60s");
    }
    console.error("Gemini API call failed:", error);
    throw error;
  }
}

export function getComponentImages(selections) {
  const getImages = (item) => {
    if (!item?.image) return [];
    return String(item.image).split(",").filter(Boolean).map(path => {
      if (path.startsWith("http")) return path;
      if (path.startsWith("thumbnails/")) return "/" + path;
      return "/thumbnails/" + path;
    });
  };

  return {
    case: getImages(selections['case']),
    gpu: getImages(selections['gpu']),
    cooler: getImages(selections['cooler']),
    motherboard: getImages(selections['motherboard']),
    ram: getImages(selections['ram']),
    cpu: getImages(selections['cpu']),
    psu: getImages(selections['psu']),
    ssd: getImages(selections['ssd']),
    'case-fan': getImages(selections['case-fan']),
    'mass-storage': getImages(selections['mass-storage']),
    speakers: getImages(selections['speakers']),
    webcam: getImages(selections['webcam']),
    'wireless-network-card': getImages(selections['wireless-network-card']),
    mouse: getImages(selections['mouse']),
    keyboard: getImages(selections['keyboard']),
    monitor: getImages(selections['monitor']),
    headphones: getImages(selections['headphones'])
  };
}
