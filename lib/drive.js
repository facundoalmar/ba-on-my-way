// Reads the "BA ON MY WAY fotos - PARA LA WEB" folder on Google Drive through the
// Drive API, using an API key. The folder must be shared as "Anyone with the link: Viewer".
//
// Env vars:
//   DRIVE_API_KEY    (required) Google API key restricted to the Drive API
//   DRIVE_FOLDER_ID  (optional) defaults to the web photo folder
//   DRIVE_API_BASE   (optional) only for local tests

const FOLDER_ID = process.env.DRIVE_FOLDER_ID || '1Msqg8vmZuU7wb4f01wpa0yO5f1kjSpsV';
const API_BASE = process.env.DRIVE_API_BASE || 'https://www.googleapis.com';
const TTL_MS = 5 * 60 * 1000;

let cache = { at: 0, files: null };

function apiKey() {
  const key = process.env.DRIVE_API_KEY;
  if (!key) throw new Error('DRIVE_API_KEY is not set');
  return key;
}

// Photos whose name starts with "x" are never shown (the photos with Florencia
// that are kept out of the general gallery).
function isAllowed(file) {
  return !/^x/i.test(file.name || '');
}

async function listFolder() {
  if (cache.files && Date.now() - cache.at < TTL_MS) return cache.files;
  try {
    const q = `'${FOLDER_ID}' in parents and trashed = false and mimeType contains 'image/'`;
    const files = [];
    let pageToken = '';
    do {
      const u = new URL(`${API_BASE}/drive/v3/files`);
      u.searchParams.set('q', q);
      u.searchParams.set('fields', 'nextPageToken,files(id,name,modifiedTime,mimeType)');
      u.searchParams.set('pageSize', '200');
      u.searchParams.set('key', apiKey());
      if (pageToken) u.searchParams.set('pageToken', pageToken);
      const r = await fetch(u);
      if (!r.ok) throw new Error(`Drive list failed: ${r.status}`);
      const j = await r.json();
      files.push(...(j.files || []));
      pageToken = j.nextPageToken || '';
    } while (pageToken);
    cache = { at: Date.now(), files: files.filter(isAllowed) };
    return cache.files;
  } catch (err) {
    console.error('drive list:', err.message);
    // If Drive is briefly unreachable, keep serving the last good listing.
    if (cache.files) return cache.files;
    throw err;
  }
}

async function downloadFile(id) {
  const u = new URL(`${API_BASE}/drive/v3/files/${encodeURIComponent(id)}`);
  u.searchParams.set('alt', 'media');
  u.searchParams.set('key', apiKey());
  const r = await fetch(u);
  if (!r.ok) throw new Error(`Drive download failed: ${r.status}`);
  return Buffer.from(await r.arrayBuffer());
}

module.exports = { listFolder, downloadFile };
