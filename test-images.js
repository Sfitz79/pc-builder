import { getPartThumbnailUrl } from './src/utils/imageScraper.js';

// Mock localStorage
global.localStorage = {
  getItem: () => null,
  setItem: () => null
};

// Mock fetch
global.fetch = async () => ({
  ok: true,
  text: async () => '<html></html>'
});

async function test() {
  const item = {
    name: "AMD Ryzen 7 9800X3D",
    image: "thumbnails/cpu_amd_ryzen_7_9800x3d.jpg"
  };
  
  console.log("Testing with item.image...");
  const urls = await getPartThumbnailUrl(item.name, "CPU", item);
  console.log("Result:", urls);

  const itemNoImg = {
    name: "Test Part"
  };
  console.log("\nTesting without item.image (fallback)...");
  const urls2 = await getPartThumbnailUrl(itemNoImg.name, "CPU", itemNoImg);
  console.log("Result:", urls2);
}

test();
