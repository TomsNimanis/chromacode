# ChromaCode

Brand-aware mosaic QR code generator that blends images into scannable QR codes — no ugly center cutout.

<img width="739" height="1600" alt="image" src="https://github.com/user-attachments/assets/7b368a3e-cc24-44b4-b58c-12f005947a52" />


**[Live Demo →](https://itlinden.lv/qr)**

Built by [IT Linden](https://itlinden.lv) · Author: Toms Nimanis

---

## What It Does

Paste a URL, upload a logo, and get a QR code where the brand image is visible through the dot pattern. The algorithm modulates dot **sizes** and **colors** per-pixel from the source image while keeping the QR scannable.

### Algorithm

- **Dark QR modules** — dot size scales with image darkness (dark area → big dot, light area → small dot)
- **Ghost dots** — colored dots appear in white QR areas where the image is dark, creating the see-through effect
- **Colors** — sampled directly from the image with saturation boost for vibrancy
- **Finder patterns** — colored with the dominant image color instead of forced black
- **Auto-tune** — analyzes image contrast and brightness, sets optimal parameters automatically
- **Multi-version** — generates 5 QR density levels (v7 through v18) simultaneously so you pick the best one

High error correction (30% redundancy) ensures scannability despite the visual modifications.

---

## Repository Structure

```
chromacode/
├── standalone/
│   ├── index.html     # Full standalone app (single HTML file)
│   └── proxy.php      # Server-side favicon proxy (bypasses CORS)
├── wordpress/
│   └── chromacode/
│       ├── chromacode.php   # WordPress plugin (single file)
│       └── readme.txt       # WP plugin readme
├── LICENSE
└── README.md
```

---

## Standalone Deployment

Deploy the `standalone/` folder to any PHP-capable web server.

```bash
# Example: upload to your server
scp standalone/* user@server:/var/www/qr.yourdomain.com/
```

- `index.html` — the full app, works on its own
- `proxy.php` — enables the **Favicon** tab to auto-fetch site icons server-side (bypasses CORS). Without it, **Upload** and **Image URL** tabs still work fine.

### No PHP? No problem.

The `index.html` works as a static file too — favicon fetching falls back to client-side CORS proxies. Upload tab always works regardless.

---

## WordPress Plugin

### Installation

1. Download or clone this repo
2. Zip the `wordpress/chromacode/` folder
3. Go to **Plugins → Add New → Upload Plugin** in WordPress admin
4. Upload the zip and activate
5. Add the `[chromacode]` shortcode to any page or post

### Settings

Go to **Settings → ChromaCode** in the WordPress admin to customize:

**Colors** — background, surface, borders, text, accent primary & secondary (button gradient)

**Text Labels** — title, subtitle, all button text, tab labels, upload prompt, status messages, URL placeholder

### Shortcode Options

```
[chromacode]
[chromacode title="QR Generator" subtitle="Create branded QR codes"]
```

### Server-Side Favicon Proxy

The WordPress plugin includes a built-in AJAX endpoint for fetching favicons server-side — no CORS issues. The Favicon tab works out of the box on any WordPress installation.

---

## Features

- Upload any image or auto-fetch favicons from URLs
- 5 QR density versions generated at once (version carousel)
- Auto-tuned parameters based on image analysis
- PNG and SVG export (mobile: native share sheet)
- Circle, rounded square, or square dot shapes
- Adjustable error correction level
- Output resolution up to 2048px
- Advanced settings: dot size range, ghost strength/threshold, colors
- Monochrome mode toggle
- White-background image detection with warning
- Fully customizable via WordPress admin (colors, all text labels)

---

## How It Compares

Inspired by [MosaicQR](https://mosaicqr.com). ChromaCode is open source, all versions are free to download (no paywall), and it can be self-hosted or embedded via WordPress.

---

## License

GPL v2 or later. See [LICENSE](LICENSE).
