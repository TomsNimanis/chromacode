=== ChromaCode QR Generator ===
Contributors: tomsnimanis
Tags: qr code, mosaic, branded qr, qr generator
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Brand-aware mosaic QR code generator. Upload a logo, get a scannable QR code with the image blended into the dot pattern.

== Description ==

ChromaCode generates QR codes where the brand logo or image is visible through the dot pattern — no ugly center cutout needed. The algorithm modulates dot sizes and colors based on the source image, creating a mosaic effect while maintaining scannability.

**Features:**

* Upload any image or auto-fetch favicons
* Multiple QR density versions generated simultaneously
* Auto-tune parameters based on image analysis
* PNG and SVG export
* Fully customizable colors and text labels via admin settings
* Mobile-friendly with native share sheet support
* Shortcode: `[chromacode]`

== Installation ==

1. Upload the `chromacode` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Settings → ChromaCode to customize colors and labels
4. Add `[chromacode]` to any page or post

== Changelog ==

= 1.0.0 =
* Initial release
