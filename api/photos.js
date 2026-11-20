// GET /api/photos?n=10
// Returns a random selection of photos from the Drive web folder, in random order.
// A new selection on every request, so each visit sees different photos.

const { listFolder } = require('../lib/drive');

function shuffle(items) {
  for (let i = items.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [items[i], items[j]] = [items[j], items[i]];
  }
  return items;
}

module.exports = async (req, res) => {
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  try {
    const url = new URL(req.url, 'http://localhost');
    const asked = parseInt(url.searchParams.get('n') || '10', 10);
    const n = Math.min(Math.max(Number.isFinite(asked) ? asked : 10, 1), 50);
    const files = await listFolder();
    const photos = shuffle(files.slice())
      .slice(0, n)
      .map((f) => ({ id: f.id, v: f.modifiedTime }));
    res.statusCode = 200;
    res.end(JSON.stringify({ photos }));
  } catch (err) {
    console.error('photos:', err.message);
    res.statusCode = 503;
    res.end(JSON.stringify({ photos: [] }));
  }
};
