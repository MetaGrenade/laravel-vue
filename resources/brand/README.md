# Brand files

The two drawings here are the source of every brand file in `public/`. Nothing in `public/` is edited by hand.

| Drawing           | For                                                                                                     | Size                    |
| ----------------- | ------------------------------------------------------------------------------------------------------- | ----------------------- |
| `logo-light.webp` | Light backgrounds: dark "Meta", blue and violet "Forger". The mark in the icons is cut out of this one. | 2000 x 667, transparent |
| `logo-dark.webp`  | Dark backgrounds: the same with a white "Meta".                                                         | 2000 x 667, transparent |

To change the logo, replace the two drawings (keep the mark on the left and a gap before the lettering) and run:

```bash
php scripts/build-brand-assets.php
```

It needs PHP's GD extension with WebP support. It writes:

| File                                                                           | Used by                                                                                                                       |
| ------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------- |
| `public/brand/logo-light.png`, `logo-dark.png` (1024 x 341)                    | The header (`AppLogo.vue` shows one or the other by the colour scheme), the sign-in layout with the side panel, the README.   |
| `public/brand/mark.png` (192 x 192)                                            | Wherever only the mark is shown (`AppLogoIcon.vue`), the error pages, the structured data of the home page, the web manifest. |
| `public/favicon.ico` (16, 32 and 48), `favicon-16x16.png`, `favicon-32x32.png` | The browser tab.                                                                                                              |
| `public/apple-touch-icon.png` (180 x 180)                                      | iPhone and iPad home screens.                                                                                                 |
| `public/android-chrome-192x192.png`, `android-chrome-512x512.png`              | The web manifest (`/site.webmanifest`).                                                                                       |

The script takes the faint haze out of the drawings, makes nearly solid pixels solid, and makes images smaller by averaging with each pixel counted by how opaque it is, so the lettering has no dark edge. `tests/Feature/BrandingTest.php` checks that every file is there with the size it is used at.

The name shown on the site and in email is not part of the images: it is `APP_NAME` in `.env`.
