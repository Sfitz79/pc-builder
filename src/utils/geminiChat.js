import {
  GPU_HIERARCHY,
  CPU_HIERARCHY,
  GAME_REQUIREMENTS,
  OS_REQUIREMENTS,
  BUILD_RECOMMENDATIONS_BY_BUDGET,
  RESOLUTION_GUIDE,
  USE_CASE_GUIDE
} from './partsKnowledgeBase';

const GEMINI_API_KEY = import.meta.env.VITE_GEMINI_API_KEY || null;
const GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-image-preview:generateContent";

function buildPartsContext(selections) {
  const parts = Object.entries(selections).filter(([, item]) => item);
  if (parts.length === 0) return "No parts selected yet.";
  return parts.map(([cat, item]) => {
    const specs = [];
    if (item.socket) specs.push(`Socket: ${item.socket}`);
    if (item.core_count) specs.push(`${item.core_count}C/${(parseInt(item.core_count) || 0)}T`);
    if (item.speed) specs.push(`Speed: ${item.speed}`);
    if (item.memory) specs.push(`VRAM: ${item.memory}GB`);
    if (item.wattage) specs.push(`${item.wattage}W`);
    if (item.price) specs.push(`£${item.price}`);
    return `${cat}: ${item.name}${specs.length ? ` (${specs.join(', ')})` : ''}`;
  }).join('\n');
}

function buildKnowledgeBase() {
  const sections = [];

  sections.push(`GPU PERFORMANCE HIERARCHY (2026):
- RTX 5090 (32GB): Best overall, 169 FPS avg 4K, $1999+
- RTX 5080 (16GB): Excellent 4K, 115 FPS avg 4K, $999+
- RTX 5070 Ti (16GB): Great 4K entry, 98 FPS 4K, $749+
- RX 9070 XT (16GB): Best AMD value, 93 FPS 4K, $600
- RX 9070 (16GB): Solid 1440p, $550
- RTX 5070 (12GB): Best value NVIDIA, $550
- RTX 5060 Ti (8-16GB): Good 1080p-1440p, $300+
- Intel Arc B580 (12GB): Best budget option, $250`);

  sections.push(`CPU PERFORMANCE TIERS (2026):
- Ryzen 7 9800X3D: Best gaming CPU, 30-38% faster than Intel in games, $460
- Ryzen 9 9950X3D: Best gaming+productivity hybrid, $675
- Ryzen 7 9700X: Best value gaming, 90-95% of 9800X3D perf at 1440p, $309
- Ryzen 5 9600X: Best budget gaming, $165
- Ryzen 5 7600: Great entry AM5, $185
- Intel Core Ultra 7 265K: Good for productivity, decent gaming, $350
- Intel Core Ultra 5 245K: Entry Arrow Lake, $280
- AMD dominates gaming at every price point in 2026.`);

  sections.push(`GAME SYSTEM REQUIREMENTS:
Cyberpunk 2077 - Min: i7-6700/R5-1600 + GTX 1060/RX 580 + 12GB RAM
              - Rec: i7-12700/R7-7800X3D + RTX 2060S/RX 5700 XT + 16GB RAM
              - Ultra 4K: i9-12900/R9-7900X + RTX 3080/RX 7900 XTX + 20GB RAM

Battlefield 6 - Min: i5-8400/R5-2600 + RTX 2060/RX 5600 XT + 16GB RAM
              - Rec: i7-10700/R7-3700X + RTX 3060 Ti/RX 6700 XT + 16GB RAM

Black Myth: Wukong - Min: i5-8400/R5-1600 + GTX 1060/RX 580 + 16GB RAM
                    - Rec: i7-9700/R5-5500 + RTX 2060/RX 5700 XT + 16GB RAM

COD: Black Ops 6 - Min: i5-6600/R5-1400 + GTX 960/RX 470 + 8GB RAM
                  - Rec: i7-6700K/R5-1600X + RTX 3060/GTX 1080 Ti + 12GB RAM
                  - 4K: i7-8700K/R7-2700X + RTX 3080/RTX 4070 + 16GB RAM`);

  sections.push(`WINDOWS 11 REQUIREMENTS: 1GHz+ dual-core CPU, 4GB RAM, 64GB storage, UEFI+Secure Boot, TPM 2.0 REQUIRED, DirectX 12 GPU. Intel 8th Gen+, AMD Ryzen 2000+ supported. Windows 10 support ended Oct 2025.`);

  sections.push(`BUILD RECOMMENDATIONS BY BUDGET (UK £):
£500-700 (1080p): Ryzen 5 5600/7600 + Arc B580/RTX 4060 + 16GB + 1TB NVMe
£800-1200 (1440p): Ryzen 7 9700X + RTX 5070/RX 9070 + 32GB DDR5 + 2TB NVMe
£1500-2000 (1440p/4K): Ryzen 7 9800X3D + RTX 5070 Ti/RX 9070 XT + 32GB + 2TB Gen5
£2000-3000 (4K): Ryzen 7 9800X3D + RTX 5080 + 32-64GB + 2-4TB Gen5
£3000+ (4K Ultra): Ryzen 9 9950X3D + RTX 5090 + 64GB + 4TB Gen5`);

  sections.push(`RESOLUTION GUIDE:
1080p: RTX 4060/Arc B580 + Ryzen 5. Great for competitive FPS.
1440p: RTX 5070/RX 9070 + Ryzen 7. The sweet spot in 2026.
4K: RTX 5080/5090 + Ryzen 7/9. Demands top-tier GPU.

GAMING PC 4K SUBSECTION:
1. Entry 4K Gaming PC
Estimated Price: £1,450 - £1,600
GPU: NVIDIA GeForce RTX 5070 12GB
CPU: AMD Ryzen 5 9600X (6 Cores)
RAM: 16GB DDR5 5600MHz
Storage: 1TB PCIe 4.0 NVMe SSD
PSU: 750W 80+ Gold

2. Mid-Range 4K Gaming PC
Estimated Price: £2,100 - £2,350
GPU: NVIDIA GeForce RTX 5070 Ti 16GB
CPU: AMD Ryzen 7 9700X (8 Cores)
RAM: 32GB DDR5 6000MHz
Storage: 2TB PCIe 4.0 NVMe SSD
PSU: 850W 80+ Gold

3. Competitive Esports 4K PC
Estimated Price: £3,000 - £3,300
GPU: NVIDIA GeForce RTX 5080 16GB
CPU: AMD Ryzen 7 9800X3D (8 Cores)
RAM: 32GB DDR5 6000MHz (Low Latency)
Storage: 2TB PCIe 5.0 NVMe SSD
PSU: 850W - 1000W Platinum PSU

4. Ultimate 4K Gaming & AI Workstation
Estimated Price: £4,500 - £5,200
GPU: NVIDIA GeForce RTX 5090 32GB
CPU: Intel Core Ultra 9 285K or AMD Ryzen 9 9900X (12+ Cores)
RAM: 64GB DDR5 6400MHz
Storage: 4TB PCIe 5.0 NVMe SSD
PSU: 1200W+ 80+ Titanium

GAMING/STREAMING 4K SUBSECTION:
1. Entry Tier (Budget 4K & Streaming)
Ideal for hitting 4K resolutions using AI-driven scaling (DLSS/FSR) and streaming at 1440p.
CPU: AMD Ryzen 5 7600X | GPU: NVIDIA GeForce RTX 5060 Ti (16GB GDDR7)
RAM: 32GB (2 x 16GB) DDR5-6000 | Storage: 1TB NVMe PCIe 4.0 SSD
Cooling: Thermalright Assassin Air Cooler
PSU: Corsair 650W 80+ Gold
Estimated Price: £1,050 - £1,200

2. Mid Tier (High-Refresh & Streaming)
Maintains solid 4K framerates in AAA titles with heavier ray-tracing, and perfectly encodes 1440p streaming output.
CPU: AMD Ryzen 7 7700X | GPU: AMD Radeon RX 9070 XT (16GB GDDR6)
RAM: 32GB (2 x 16GB) DDR5-6000 | Storage: 2TB NVMe PCIe 4.0 SSD
Cooling: DeepCool AK620 Air Cooler
PSU: Corsair 750W 80+ Gold
Estimated Price: £1,450 - £1,650

3. Competitive Tier (Esports & Ultra-Settings 4K)
Built to maximize FPS for competitive gaming at 4K, while managing background 1440p broadcasting without dropping frames.
CPU: AMD Ryzen 7 9800X3D | GPU: NVIDIA GeForce RTX 5080 (16GB GDDR6X)
RAM: 32GB (2 x 16GB) DDR5-6400 | Storage: 2TB NVMe PCIe 4.0 SSD
Cooling: Corsair iCUE H115i RGB Liquid Cooler
PSU: Corsair 850W 80+ Gold
Estimated Price: £2,300 - £2,600

4. Ultimate Tier (Max Settings 4K, VR & Multitasking)
The uncompromised build for running ultra-settings 4K gaming, 1440p streaming, and intensive local AI rendering.
CPU: AMD Ryzen 9 9950X | GPU: NVIDIA GeForce RTX 5090 (24GB GDDR6X)
RAM: 64GB (2 x 32GB) DDR5-6400 | Storage: 4TB NVMe PCIe 5.0 SSD
Cooling: ASUS ROG Ryujin III 360 Liquid Cooler
PSU: Corsair 1200W 80+ Platinum
Estimated Price: £4,200 - £4,600`);

  sections.push(`USE CASE PRIORITIES:
Gaming: GPU > CPU. X3D CPUs give 15-25% more FPS.
Streaming: 8+ CPU cores, 32GB RAM, NVIDIA GPU for NVENC encoder.
3D Rendering: High core count CPU, large VRAM GPU, 64GB+ RAM, Gen5 storage.
General: Balanced mid-range parts, 16-32GB RAM, fast SSD.`);

  return sections.join('\n\n');
}

function buildSystemPrompt(selections) {
  const partsContext = buildPartsContext(selections);
  const knowledgeBase = buildKnowledgeBase();

  return `You are PCTG AI Assistant, an expert product advisor for PCTG (PCTechGuyOnline), a UK-based custom PC builder. The customer does NOT build the PC themselves — PCTG builds it for them. Never give assembly/BiOS/setup tips.

Your ONLY job: help customers choose the right components.
1. Ask about budget, use case (gaming/streaming/rendering/work), target resolution/fps, brand preferences
2. Recommend compatible parts (socket, DDR gen, PSU wattage, case clearance)
3. Price-to-performance advice — best value at every budget
4. Match parts to the games/applications they use
5. Explain trade-offs honestly

KNOWLEDGE BASE (use this for accurate recommendations):
${knowledgeBase}

CURRENT PARTS SELECTED BY USER:
${partsContext}

Guidelines:
- Friendly, enthusiastic, concise (2-4 paragraphs max).
- Use **bold** for part names.
- Refer to the knowledge base above for accurate specs and recommendations.
- If asked about a game, check the game requirements above and recommend parts that meet or exceed them.
- Mention PCTG can source all parts and build the system.
- Use £ pricing.
- Current date: June 2026.`;
}

export async function askGemini(userMessage, selections = {}, conversationHistory = []) {
  try {
    const systemPrompt = buildSystemPrompt(selections);

    const contents = [
      { role: "user", parts: [{ text: systemPrompt }] },
      { role: "model", parts: [{ text: "Understood. I'm ready to help customers choose the right components!" }] }
    ];

    for (const msg of conversationHistory) {
      contents.push({
        role: msg.sender === 'bot' ? 'model' : 'user',
        parts: [{ text: msg.text }]
      });
    }

    contents.push({ role: "user", parts: [{ text: userMessage }] });

    const response = await fetch(`${GEMINI_API_URL}?key=${GEMINI_API_KEY}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        contents,
        generationConfig: {
          temperature: 0.7,
          topK: 40,
          topP: 0.95,
          maxOutputTokens: 1024
        }
      })
    });

    if (!response.ok) {
      const errorText = await response.text();
      throw new Error(`Gemini API error: ${response.status} ${errorText}`);
    }

    const data = await response.json();
    const text = data.candidates?.[0]?.content?.parts?.[0]?.text;
    if (!text) throw new Error("No response text from Gemini");
    return text;
  } catch (error) {
    console.error("Gemini chat failed:", error);
    return null;
  }
}

export async function generateDynamicTip(selections = {}) {
  const selectedCategories = Object.keys(selections).filter(k => selections[k]);
  if (selectedCategories.length === 0) {
    const prompts = [
      "What will you use your PC for? Gaming, work, or both? I'll recommend the best components!",
      "What's your budget? I'll help you pick the best parts for your money.",
      "Not sure where to start? Tell me what games you play and your target resolution!",
      "Need help choosing parts? Tell me your budget and use case — I'll suggest a full build.",
      "PCTG builds custom PCs to your spec — pick your components and they'll handle the rest!"
    ];
    return prompts[Math.floor(Math.random() * prompts.length)];
  }

  try {
    const partsContext = buildPartsContext(selections);
    const prompt = `Give ONE short question or suggestion (1-2 sentences) to help this customer choose better components. No assembly/BiOS tips.\n\nCurrent parts:\n${partsContext}\n\nAsk about budget, upgrades, resolution, or suggest a missing component. Use **bold** for part names.`;

    const response = await fetch(`${GEMINI_API_URL}?key=${GEMINI_API_KEY}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        contents: [{ role: "user", parts: [{ text: prompt }] }],
        generationConfig: { temperature: 0.8, maxOutputTokens: 150 }
      })
    });

    if (!response.ok) throw new Error("API error");
    const data = await response.json();
    return data.candidates?.[0]?.content?.parts?.[0]?.text || "What's your target budget for this build?";
  } catch {
    const fallbacks = [
      "What's your target resolution — 1080p, 1440p, or 4K?",
      "Are you building for gaming, work, or a mix of both?",
      "Would you like me to suggest a CPU that matches your GPU's performance tier?",
      "Do you have a preferred brand for your graphics card?",
      "What's your ideal budget range for this build?"
    ];
    return fallbacks[Math.floor(Math.random() * fallbacks.length)];
  }
}
