// GET /api/photo?id=<driveFileId>&v=<modifiedTime>
// Serves one photo from the Drive web folder, resized to 1600 px and converted to WebP.
// The v parameter changes whenever the file is replaced, so the CDN cache never goes stale.

const sharp = require('sharp');
const { listFolder, downloadFile } = require('../lib/drive');

const ID_RE = /^[A-Za-z0-9_-]{10,100}$/;
const MAX_CACHED = 60;
const cache = new Map();

module.exports = async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const id = url.searchParams.get('id') || '';
  if (!ID_RE.test(id)) {
    res.statusCode = 400;
    res.end('bad id');
    return;
  }
  try {
    // Only files that are in the allowed folder listing can be served.
    const files = await listFolder();
    if (!files.some((f) => f.id === id)) {
      res.statusCode = 404;
      res.end('not found');
      return;
    }
    // Converted photos are kept in memory, so a warm instance keeps serving them if Drive is slow.
    const key = id + '|' + (url.searchParams.get('v') || '');
    let out = cache.get(key);
    if (!out) {
      const original = await downloadFile(id);
      out = await sharp(original, { failOn: 'none' })
        .rotate()
        .resize({ width: 1600, height: 1600, fit: 'inside', withoutEnlargement: true })
        .webp({ quality: 80 })
        .toBuffer();
      cache.set(key, out);
      if (cache.size > MAX_CACHED) cache.delete(cache.keys().next().value);
    }
    res.statusCode = 200;
    res.setHeader('Content-Type', 'image/webp');
    res.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
    res.end(out);
  } catch (err) {
    console.error('photo:', err.message);
    res.statusCode = 503;
    res.end('unavailable');
  }
};
