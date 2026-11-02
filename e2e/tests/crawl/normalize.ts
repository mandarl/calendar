/**
 * Pure normalisation of crawl output so goldens only change when behaviour does. No I/O, no environment access:
 * a rebuilt (Cake 5) run extends this list (CSRF/form-protection field names differ) rather than replacing it.
 */
export function normalizeBody(body: string): string {
  return body
    .replace(/\r\n/g, '\n')
    // CSRF + form protection tokens (names differ per framework version; values always differ per request)
    .replace(/(name="_csrfToken"[^>]*value=")[^"]*(")/g, '$1<csrf>$2')
    .replace(/(value=")[^"]*("[^>]*name="_csrfToken")/g, '$1<csrf>$2')
    .replace(/(name="_Token\[(?:fields|unlocked|debug)\]"[^>]*value=")[^"]*(")/g, '$1<token>$2')
    .replace(/(value=")[^"]*("[^>]*name="_Token\[(?:fields|unlocked|debug)\]")/g, '$1<token>$2')
    .replace(/(name="edit_key"[^>]*value=")[^"]*(")/g, '$1<edit-key>$2')
    .replace(/(value=")[^"]*("[^>]*name="edit_key")/g, '$1<edit-key>$2')
    // Braintree client token embedded in the registration page script
    .replace(/(var clientToken = ')[^']*(')/g, '$1<client-token>$2')
    // FormHelper::postLink() names its hidden forms (and the onclick that submits them) with a uniqid()
    .replace(/post_[0-9a-f]{16,32}/g, 'post_<id>')
    // "now" stamps in generated feeds: iCal DTSTAMP and the RSS channel pubDate (item pubDates are event data)
    .replace(/DTSTAMP:\d{8}T\d{6}Z/g, 'DTSTAMP:<now>')
    .replace(/(<\/description><pubDate>)[^<]*(<\/pubDate><item>)/g, '$1<now>$2')
    // Server time printed on error pages
    .replace(/\b\d{1,2}:\d{2}:\d{2}\s*UTC\b/g, '<time> UTC')
    // Cache-busting query strings added to assets
    .replace(/(\.(?:css|js))\?\d+/g, '$1?<v>')
    // Trailing whitespace noise
    .replace(/[ \t]+$/gm, '');
}

/** Short stable hash for the per-role summary (FNV-1a, 32-bit, hex). */
export function hash(s: string): string {
  let h = 0x811c9dc5;
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i);
    h = Math.imul(h, 0x01000193) >>> 0;
  }
  return h.toString(16).padStart(8, '0');
}
