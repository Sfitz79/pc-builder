import { readFileSync, writeFileSync, mkdirSync, existsSync } from "fs";
import { join, dirname } from "path";
import { fileURLToPath } from "url";

const __dirname = dirname(fileURLToPath(import.meta.url));

const PROXY_URL = process.env.VITE_LEONARDO_PROXY_URL || "https://img-gen-pctg.vercel.app/api/generate";
const OUTPUT_DIR = join(__dirname, "..", "public", "promo");

const SCENES = [
  {
    id: "scene1-floating-components",
    prompt: `Ultra-high-end cinematic tech commercial product shot. Floating computer components including a modern CPU, high-end GPU, sleek RGB RAM sticks, and an AIO liquid cooler, drifting and rotating gently in mid-air. Pristine, minimalist studio white background. Bright, clean, premium commercial lighting with soft red and blue RGB reflections bouncing off the metallic surfaces of the hardware. Slow, smooth camera dolly forward, increasing zoom from 1.0x to 1.1x. Flawless 60fps, shallow depth of field, photorealistic 8k resolution, NVIDIA style.`,
    negative: "Text, letters, words, messy, dark background, cables, hands, glitch, jittery camera.",
  },
  {
    id: "scene2-assembly",
    prompt: `Close-up macro tracking shot of a modern gaming PC assembly. A premium matte black dual-chamber computer case sits on a clean wooden technician workbench. Inside the case, a high-end graphics card is slotted into the motherboard, the CPU cooler pump block activates with a soft geometric light, and multiple internal RGB cooling fans begin spinning smoothly. Ultra-shallow depth of field with gorgeous background bokeh. Smooth camera pan to the right. Tech-focused, moody, professional studio atmosphere, ASUS ROG aesthetic, photorealistic.`,
    negative: "Text, humans, tools, clutter, shaky cam, sudden cuts, low quality.",
  },
  {
    id: "scene3-rotation-packing",
    prompt: `Dynamic 360-degree camera orbit shot around a fully built, glowing gaming PC floating in a dark studio space with ambient red and blue lighting. Smooth cinematic transition as a premium cardboard shipping box cleanly closes around the computer. Hard cut to a close-up of a delivery van's rear door shutting firmly, then a wide shot of the delivery van driving away down a clean asphalt road into a beautiful warm sunset at dusk. High-end commercial look, smooth transitions, cinematic color grading.`,
    negative: "Bad lighting, text, graphics, old van, daytime, shaky footage, artifacting.",
  },
  {
    id: "scene4-arrival-boot",
    prompt: `High-end tracking shot inside a dimly lit modern gaming bedroom at dusk. A pristine computer setup sits on a desk. A hand presses the physical power button on top of a custom PC chassis. Instantly, vibrant red and blue RGB lighting pulses to life inside the transparent glass case and across the desk setup. The ultrawide monitor screen flashes on, displaying a clean, dark tech background with a glowing neon abstract shape in the center. Smooth, slow camera dolly forward into the screen from 1.0x to 1.15x.`,
    negative: "Bright daylight, messy room, existing logos, text, blurry, fast motion.",
  },
  {
    id: "scene5-loading-loop",
    prompt: `Seamless, futuristic tech ambient looping background video. A dark, minimal slate-grey high-tech environment with geometric accent lines. A central, abstract glowing emblem pulses gently with soft red and blue breathing light effects on a consistent 2.5-second rhythm. The camera executes a very slow, continuous, steady pan to the left. Perfectly consistent lighting, calm electronic atmosphere, designed for a smooth video loop with no sudden movements or changes. Premium UI loading screen aesthetic.`,
    negative: "Hard cuts, flashing lights, rapid motion, text, hands, humans, sudden shifts in lighting.",
  },
];

async function generateImage(scene) {
  console.log(`\n[${scene.id}] Generating...`);

  const body = {
    prompt: scene.prompt,
    negative_prompt: scene.negative,
    provider: "leonardo",
  };

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 120000);

  try {
    const res = await fetch(PROXY_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      signal: controller.signal,
      body: JSON.stringify(body),
    });

    clearTimeout(timeout);

    if (!res.ok) {
      const err = await res.text();
      throw new Error(`API error ${res.status}: ${err}`);
    }

    const contentType = res.headers.get("content-type") || "image/png";
    const ext = contentType.includes("jpeg") ? "jpg" : "png";
    const buffer = Buffer.from(await res.arrayBuffer());

    const filePath = join(OUTPUT_DIR, `${scene.id}.${ext}`);
    writeFileSync(filePath, buffer);
    console.log(`[${scene.id}] Saved: ${filePath} (${(buffer.length / 1024 / 1024).toFixed(2)} MB)`);
    return filePath;
  } catch (err) {
    clearTimeout(timeout);
    console.error(`[${scene.id}] FAILED: ${err.message}`);
    return null;
  }
}

async function main() {
  if (!existsSync(OUTPUT_DIR)) {
    mkdirSync(OUTPUT_DIR, { recursive: true });
  }

  console.log(`Generating ${SCENES.length} scene images...`);
  console.log(`Proxy URL: ${PROXY_URL}`);
  console.log(`Output: ${OUTPUT_DIR}\n`);

  for (const scene of SCENES) {
    await generateImage(scene);
  }

  console.log("\nDone.");
}

main().catch(console.error);
