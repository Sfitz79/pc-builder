const memoryCache = new Map();
const STORAGE_KEY = "pctg-part-thumbnails";

export async function getPartThumbnailUrl(partName, category, item = {}) {
  // If the item already has image path(s), handle them
  if (item.image) {
    const images = String(item.image).split(',').filter(Boolean);
    const resolved = images.map(img => {
      const trimmed = img.trim();
      if (trimmed.startsWith('http') || trimmed.startsWith('data:')) {
        return trimmed;
      }
      
      // Ensure we have a clean relative path
      let path = trimmed;
      if (path.startsWith('./')) {
        path = path.slice(2);
      } else if (path.startsWith('/')) {
        path = path.slice(1);
      }
      
      // Prefix with BASE_URL if available (Vite)
      const base = (import.meta.env && import.meta.env.BASE_URL) || './';
      
      // If we're on file protocol or similar, always use relative
      if (typeof window !== 'undefined' && window.location.protocol === 'file:') {
        return `./${path}`;
      }

      if (base === './' || base === '/') {
        return path;
      }
      
      const fullPath = base.endsWith('/') ? `${base}${path}` : `${base}/${path}`;
      // Ensure no double slashes at start except for protocol
      return fullPath.replace(/\/+/g, '/').replace(':/', '://');
    });
    if (resolved.length > 0) return resolved;
  }

  const cacheKey = `${category}::${partName}`.toLowerCase();
  const cached = getCachedValue(cacheKey);
  if (cached) {
    // Cache can store either string or array
    return Array.isArray(cached) ? cached : [cached];
  }

  const query = `${partName} ${category} pc component product image`;
  const url = `https://r.jina.ai/http://www.bing.com/images/search?q=${encodeURIComponent(
    query
  )}`;

  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000);

    const response = await fetch(url, { signal: controller.signal });
    clearTimeout(timeoutId);
    
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    const text = await response.text();
    const scraped = extractImageUrls(text);
    if (scraped.length > 0) {
      setCachedValue(cacheKey, scraped);
      return scraped;
    }
    return [];
  } catch (error) {
    console.warn(`Failed to fetch thumbnail for ${partName}:`, error.message);
    return [];
  }
}

function extractImageUrls(text) {
  if (!text) return [];
  
  const urls = new Set();
  
  // Try to find multiple direct matches
  const directMatches = text.matchAll(/https?:\/\/[^\s"'()<>]+?\.(?:jpg|jpeg|png|webp)/gi);
  for (const match of directMatches) {
    const sanitized = sanitizeUrl(match[0]);
    if (sanitized && !sanitized.includes('bing.com') && !sanitized.includes('jina.ai')) {
      urls.add(sanitized);
    }
    if (urls.size >= 3) break; // Get up to 3
  }

  if (urls.size === 0) {
    const encodedMatches = text.matchAll(/murl&quot;:&quot;(https?:\/\/[^"&]+)&quot;/gi);
    for (const match of encodedMatches) {
      const sanitized = sanitizeUrl(match[1].replace(/\\u002f/g, "/"));
      if (sanitized) urls.add(sanitized);
      if (urls.size >= 3) break;
    }
  }

  return Array.from(urls);
}

function sanitizeUrl(url) {
  try {
    const parsed = new URL(url);
    return parsed.toString();
  } catch {
    return null;
  }
}

function getFallbackThumbnail(partName, category) {
  const text = encodeURIComponent(`${category}: ${partName}`.slice(0, 36));
  return `https://placehold.co/240x140/111122/00eaff?text=${text}`;
}

function getCachedValue(key) {
  if (memoryCache.has(key)) {
    return memoryCache.get(key);
  }

  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return null;
    }
    const parsed = JSON.parse(raw);
    const value = parsed[key];
    if (value) {
      memoryCache.set(key, value);
      return value;
    }
  } catch {
    return null;
  }

  return null;
}

function setCachedValue(key, value) {
  memoryCache.set(key, value);

  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const parsed = raw ? JSON.parse(raw) : {};
    parsed[key] = value;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(parsed));
  } catch {
    // Ignore storage write failures.
  }
}
