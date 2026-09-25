// =====================================================
// assets/js/html.js — building HTML from data safely
//
// Loaded in <head> on every app page, before any page script or inline
// script, so these are always defined. There used to be a private copy of
// the escaper in almost every page script, and they disagreed: most left the
// single quote alone, and transfer.js's left both quotes alone even though it
// was used inside attributes.
//
// The server no longer strips tags from input, so text arrives exactly as the
// user typed it. Everything that comes from data goes through escHtml()
// before it is put into markup — text and attribute values alike.
// =====================================================

/** Escapes a value for use as HTML text or inside a quoted attribute. */
function escHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value).replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
}

/**
 * A colour for a style attribute: a hex colour passes through, anything else
 * becomes the fallback, so a stored value cannot smuggle in other CSS.
 */
function cssColor(value, fallback = '#9ca3af') {
    return /^#[0-9a-f]{3,8}$/i.test(String(value ?? '')) ? String(value) : fallback;
}
