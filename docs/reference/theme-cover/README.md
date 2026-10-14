# Theme cover (`bsa-portal/screenshot.png`)

The 1200 × 900 image WordPress shows for the BSA Portal theme in Appearance → Themes.

`cover.html` lays it out. It needs these files next to it (not kept here):

- `home-desktop.png`: the live home page rendered at 1366 × 900;
- `account-mobile.png`: the live My Account page rendered at 390 × 844;
- `crest.png`: the crest from the Media Library (`bsa-logo-premium.png`);
- `playfair-display-latin.woff2`, `plus-jakarta-sans-latin.woff2` from
  `bsa-portal/assets/fonts/`.

`node shoot.js` renders `screenshot-raw.png`; it is then reduced to a 256-colour PNG
(Pillow `quantize(256)`, about 130 KB) and saved as `bsa-portal/screenshot.png`.
Re-render it when the home or sign-in page changes noticeably.
