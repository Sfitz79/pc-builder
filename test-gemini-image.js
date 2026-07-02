#!/usr/bin/env node
import fs from 'fs';

const API_KEY = process.env.GEMINI_API_KEY || process.env.GENERATIVE_API_KEY || '';
if (!API_KEY) {
  console.error('Error: set GEMINI_API_KEY environment variable');
  process.exit(1);
}

const MODEL_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-image-preview:generateContent';
const prompt = process.argv.slice(2).join(' ') || 'Photorealistic gaming PC on a desk, 4K, cinematic lighting, no logos';

async function main() {
  try {
    const resp = await fetch(`${MODEL_URL}?key=${API_KEY}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        contents: [{ parts: [{ text: prompt }] }],
        generationConfig: {
          responseModalities: ["IMAGE"],
          // Optional: request image size/format if supported by model
          image: { width: 1024, height: 1024, format: 'PNG' }
        }
      })
    });

    if (!resp.ok) {
      const errText = await resp.text();
      throw new Error(`API error: ${resp.status} ${errText}`);
    }

    const data = await resp.json();

    let saved = 0;
    const candidates = data.candidates || [];
    for (let ci = 0; ci < candidates.length; ci++) {
      const parts = candidates[ci].content?.parts || [];
      for (let pi = 0; pi < parts.length; pi++) {
        const part = parts[pi];
        if (part.inlineData && part.inlineData.data) {
          const mime = part.inlineData.mimeType || 'image/png';
          const ext = mime.includes('png') ? 'png' : mime.includes('jpeg') ? 'jpg' : 'bin';
          const out = `gemini_image_${ci}_${pi}.${ext}`;
          fs.writeFileSync(out, Buffer.from(part.inlineData.data, 'base64'));
          console.log('Saved image:', out);
          saved++;
        } else if (part.uri) {
          // If model returned a uri, try to download it
          try {
            const r = await fetch(part.uri);
            if (r.ok) {
              const buf = Buffer.from(await r.arrayBuffer());
              const out = `gemini_image_${ci}_${pi}`;
              fs.writeFileSync(out, buf);
              console.log('Downloaded uri to:', out);
              saved++;
            } else {
              console.warn('Failed to download uri:', part.uri, r.status);
            }
          } catch (e) {
            console.warn('Error fetching uri:', part.uri, e.message);
          }
        }
      }
    }

    if (saved === 0) {
      console.error('No image data found in response. Full response (truncated):');
      console.error(JSON.stringify(data, null, 2).slice(0, 8000));
      process.exit(2);
    }
  } catch (err) {
    console.error('Request failed:', err.message || err);
    process.exit(3);
  }
}

main();
