<?php
// includes/avatar.php
//
// Avatars are drawn in the page as initials in a coloured circle.
//
// They used to be <img> tags pointing at ui-avatars.com, which meant one request to a
// third-party service for every row on the page: 161 on the tenants list, 961 on the
// payments list. Those queue up behind the browser's per-host connection limit and made
// the tables take seconds to settle. Initials cost nothing to render, work offline, and
// look the same every time.

require_once __DIR__ . '/uploads.php';

/** Initials for a name: "Juan Dela Cruz" -> "JD". Falls back to "?" for an empty name. */
function avatarInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return '?';
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/**
 * A colour for this name, picked from a fixed palette.
 * The same person always gets the same colour, so rows stay recognisable between page loads
 * (the old background=random gave everyone a new colour on every request).
 */
function avatarColor(string $name): string {
    $palette = [
        '#2563eb', '#0d9488', '#7c3aed', '#db2777', '#ea580c',
        '#059669', '#4f46e5', '#b91c1c', '#0891b2', '#9333ea',
    ];
    return $palette[crc32(mb_strtolower(trim($name))) % count($palette)];
}

/**
 * Avatar markup: the tenant's uploaded photo when there is one, otherwise their initials.
 *
 * @param string      $name       Full name, used for the initials and the colour.
 * @param int         $size       Width and height in pixels.
 * @param string      $class      Extra classes for spacing, e.g. 'me-2 shadow-sm'.
 * @param string|null $pictureUrl Stored profile picture path, if the record has one.
 * @param string      $prefix     Path back to the project root for a local picture, e.g. '../'.
 */
function avatarHtml(string $name, int $size = 32, string $class = '', ?string $pictureUrl = null, string $prefix = ''): string {
    $src = uploadSrc($pictureUrl, $prefix);
    if ($src) {
        return '<img src="' . htmlspecialchars($src) . '" class="rounded-circle ' . htmlspecialchars($class) . '"'
             . ' width="' . $size . '" height="' . $size . '" style="object-fit:cover;"'
             . ' alt="' . htmlspecialchars($name) . '" loading="lazy">';
    }

    // Keep the text proportional to the circle, and readable at the 20px sizes used in tables.
    $fontSize = max(9, (int)round($size * 0.42));
    return '<span class="avatar-initials ' . htmlspecialchars($class) . '"'
         . ' style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . $fontSize . 'px;'
         . 'background:' . avatarColor($name) . ';"'
         . ' title="' . htmlspecialchars($name) . '" aria-label="' . htmlspecialchars($name) . '">'
         . htmlspecialchars(avatarInitials($name))
         . '</span>';
}
