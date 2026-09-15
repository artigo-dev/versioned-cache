# Source for the README header

`plate.html` is the source of `docs/header.png`, the plate at the top of the
main README.

It is **rendered** rather than shipped as SVG because it uses **Rajdhani**, and
an SVG referenced from a markdown file cannot load a webfont: GitHub renders it
through `<img>`, where external resources are blocked. A PNG carries the
typeface with it.

To re-render, put the Rajdhani `woff2` files in `fonts/` beside this file (they
live in the ovos console repository under `site/fonts/rajdhani/`, and are not
committed here) and capture the plate at twice the scale:

```bash
msedge --headless --disable-gpu --force-device-scale-factor=2 \
  --allow-file-access-from-files --window-size=940,250 \
  --screenshot=../header.png file:///absolute/path/to/plate.html
```

The palette is deliberately not the ovos console's cyan and yellow: same
typographic system, different house.
