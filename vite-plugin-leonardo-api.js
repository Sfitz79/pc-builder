import { readFileSync, readdirSync, statSync } from "fs";
import { join, extname } from "path";
import https from "https";
import http from "http";

const FOLDER = "C:/PCTG/leonardo-output";
const PROXY_URL = "http://localhost:8000";

const MIME_TYPES = {
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".webp": "image/webp"
};

function getMimeType(filename) {
  const ext = extname(filename).toLowerCase();
  return MIME_TYPES[ext] || "application/octet-stream";
}

export default function leonardoApiPlugin() {
  return {
    name: "leonardo-api",
    configureServer(server) {
      server.middlewares.use("/api/leonardo-latest", (req, res) => {
        const headers = {
          "Content-Type": "application/json",
          "Access-Control-Allow-Origin": "*"
        };
        
        try {
          const files = readdirSync(FOLDER).filter(
            f => f.endsWith(".png") || f.endsWith(".jpg") || f.endsWith(".jpeg") || f.endsWith(".webp")
          );

          if (!files.length) {
            res.writeHead(200, headers);
            res.end(JSON.stringify({ imagePath: null }));
            return;
          }

          const sorted = files.sort((a, b) => {
            const aTime = statSync(join(FOLDER, a)).mtimeMs;
            const bTime = statSync(join(FOLDER, b)).mtimeMs;
            return bTime - aTime;
          });

          res.writeHead(200, headers);
          res.end(JSON.stringify({ 
            filename: sorted[0],
            imagePath: `/leonardo-images/${sorted[0]}`
          }));
        } catch (e) {
          console.error("Leonardo API error:", e);
          res.writeHead(500, headers);
          res.end(JSON.stringify({ error: "Failed to read Leonardo folder" }));
        }
      });

      server.middlewares.use("/leonardo-images/:filename", (req, res) => {
        const filename = req.params.filename;
        const filepath = join(FOLDER, filename);

        try {
          const buffer = readFileSync(filepath);
          const mimeType = getMimeType(filename);
          
          res.writeHead(200, {
            "Content-Type": mimeType,
            "Access-Control-Allow-Origin": "*",
            "Cache-Control": "public, max-age=3600"
          });
          res.end(buffer);
        } catch (e) {
          console.error("Failed to serve image:", e);
          res.writeHead(404);
          res.end("Image not found");
        }
      });

      server.middlewares.use("/api/dalle-proxy/generate", async (req, res) => {
        const headers = {
          "Content-Type": "application/json",
          "Access-Control-Allow-Origin": "*"
        };

        if (req.method !== "POST") {
          res.writeHead(405, headers);
          res.end(JSON.stringify({ error: "Method not allowed" }));
          return;
        }

        let body = "";
        req.on("data", chunk => body += chunk);
        req.on("end", async () => {
          try {
            const { prompt } = JSON.parse(body);
            if (!prompt) {
              res.writeHead(400, headers);
              res.end(JSON.stringify({ error: "Missing prompt" }));
              return;
            }

            const postData = JSON.stringify({ prompt, n: 1, size: "1024x1024", quality: "standard" });
            
            const proxyReq = http.request(`${PROXY_URL}/v1/images/generations`, {
              method: "POST",
              headers: {
                "Content-Type": "application/json",
                "Content-Length": Buffer.byteLength(postData)
              }
            }, (proxyRes) => {
              let data = "";
              proxyRes.on("data", chunk => data += chunk);
              proxyRes.on("end", () => {
                try {
                  const result = JSON.parse(data);
                  res.writeHead(200, headers);
                  res.end(JSON.stringify(result));
                } catch (e) {
                  console.error("Proxy parse error:", e);
                  res.writeHead(502, headers);
                  res.end(JSON.stringify({ error: "Invalid response from proxy" }));
                }
              });
            });

            proxyReq.on("error", (e) => {
              console.error("Proxy request error:", e);
              res.writeHead(503, headers);
              res.end(JSON.stringify({ error: "Proxy server unreachable" }));
            });

            proxyReq.write(postData);
            proxyReq.end();
          } catch (e) {
            console.error("DALL-E Proxy error:", e);
            res.writeHead(500, headers);
            res.end(JSON.stringify({ error: "Failed to generate image" }));
          }
        });
      });
    }
  };
}