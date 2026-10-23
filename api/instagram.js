// Returns the latest Instagram posts for the site's strip.
// Needs an env var INSTAGRAM_TOKEN in Vercel (Instagram Login long-lived token).
// Without it, the endpoint answers 503 and the page simply hides the strip.
module.exports = async (req, res) => {
  const token = process.env.INSTAGRAM_TOKEN;
  if (!token) {
    res.status(503).json({ error: 'not_configured' });
    return;
  }
  try {
    const url = 'https://graph.instagram.com/me/media' +
      '?fields=id,caption,media_type,media_url,thumbnail_url,permalink&limit=6' +
      '&access_token=' + encodeURIComponent(token);
    const r = await fetch(url);
    if (!r.ok) {
      res.status(502).json({ error: 'upstream_' + r.status });
      return;
    }
    const data = await r.json();
    res.setHeader('Cache-Control', 's-maxage=3600, stale-while-revalidate=86400');
    res.status(200).json({ data: data.data || [] });
  } catch (e) {
    res.status(502).json({ error: 'fetch_failed' });
  }
};
