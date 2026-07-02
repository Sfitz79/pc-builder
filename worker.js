const MODELS = {
  flux: "@cf/blackforestlabs/ux-1-schnell",
  dreamshaper: "@cf/lykon/dreamshaper-8-lcm",
  sdxl: "@cf/stabilityai/stable-diffusion-xl-base-1.0",
  sdxlLightning: "@cf/bytedance/stable-diffusion-xl-lightning",
};

const MODEL_PRIORITY = ["flux", "dreamshaper", "sdxl"];

export default {
    async fetch(request, env) {
        const API_KEY = env.API_KEY;
        const url = new URL(request.url);
        const auth = request.headers.get("Authorization");

        if (auth !== `Bearer ${API_KEY}`) {
            return json({ error: "Unauthorized" }, 401);
        }

        if (request.method !== "POST" || url.pathname !== "/") {
            return json({ error: "Not allowed" }, 405);
        }

        try {
            const { prompt, model } = await request.json();
            if (!prompt) return json({ error: "Prompt is required" }, 400);

            const modelId = MODELS[model] || MODELS.flux;
            let lastError;

            const modelsToTry = model
                ? [modelId]
                : MODEL_PRIORITY.map(m => MODELS[m]);

            for (const tryModel of modelsToTry) {
                try {
                    const result = await env.AI.run(tryModel, { prompt });
                    return new Response(result, {
                        headers: { "Content-Type": "image/png" },
                    });
                } catch (e) {
                    lastError = e;
                    continue;
                }
            }

            throw lastError || new Error("All models failed");
        } catch (err) {
            return json({ error: "Failed to generate image", details: err.message }, 500);
        }
    },
};

// 📦 Function to return JSON responses
function json(data, status = 200) {
    return new Response(JSON.stringify(data), {
        status,
        headers: { "Content-Type": "application/json" },
    });
}
