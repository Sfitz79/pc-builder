const fs = require("fs");
fetch("https://img-gen-pctg.vercel.app/api/generate", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({
    prompt: "Photorealistic custom gaming PC build. Show a fully assembled PC from 3/4 angle through tempered glass side panel. Case: Fractal Design North Mesh White with wood front panel. GPU: MSI RTX 4080 Suprim X. Components visible inside: CPU with air cooler, motherboard, RAM. PC on desk with monitor, keyboard, mouse. Dark studio background, cinematic lighting, 4K detail.",
    provider: "leonardo",
  }),
})
  .then(async (r) => {
    const buffer = Buffer.from(await r.arrayBuffer());
    fs.writeFileSync("test-leonardo-output.png", buffer);
    console.log("Saved:", buffer.length, "bytes");
  })
  .catch(console.error);
