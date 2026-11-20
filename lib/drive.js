// Reads the "BA ON MY WAY fotos - PARA LA WEB" folder on Google Drive through the
// Drive API, using an API key. The folder must be shared as "Anyone with the link" (any role).
//
// Env vars:
//   DRIVE_API_KEY    (required) Google API key restricted to the Drive API
//   DRIVE_FOLDER_ID  (optional) defaults to the web photo folder
//   DRIVE_API_BASE   (optional) only for local tests

const FOLDER_ID = process.env.DRIVE_FOLDER_ID || '1Msqg8vmZuU7wb4f01wpa0yO5f1kjSpsV';
const API_BASE = process.env.DRIVE_API_BASE || 'https://www.googleapis.com';
// The folder is checked twice a day, at 12:00 and 18:00 in Buenos Aires (UTC-3;
// Argentina has no daylight saving). The first request after each check time reads
// the folder again, so photos uploaded at any hour show up at the next check.
const CHECK_HOURS_UTC = [15, 21];

function lastCheckTime(now) {
  const local = new Date(now - 3 * 3600e3); // Buenos Aires wall clock, read as UTC
  const day = Date.UTC(local.getUTCFullYear(), local.getUTCMonth(), local.getUTCDate());
  const today = CHECK_HOURS_UTC.map((h) => day + h * 3600e3).filter((t) => t <= now);
  if (today.length) return today[today.length - 1];
  return day - 24 * 3600e3 + CHECK_HOURS_UTC[CHECK_HOURS_UTC.length - 1] * 3600e3;
}

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
  if (cache.files && cache.at >= lastCheckTime(Date.now())) return cache.files;
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

module.exports = { listFolder, downloadFile, lastCheckTime };
