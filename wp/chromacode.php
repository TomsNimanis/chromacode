<?php
/**
 * Plugin Name: ChromaCode QR Generator
 * Plugin URI: https://itlinden.lv
 * Description: Brand-aware mosaic QR code generator that blends images into scannable QR codes.
 * Version: 1.0.0
 * Author: Toms Nimanis
 * Author URI: https://itlinden.lv
 * License: GPL v2 or later
 * Text Domain: chromacode
 */

if (!defined('ABSPATH')) exit;

define('CHROMACODE_VERSION', '1.0.0');
define('CHROMACODE_PATH', plugin_dir_path(__FILE__));
define('CHROMACODE_URL', plugin_dir_url(__FILE__));

// ═══════════════════════════════════════
// SERVER-SIDE FAVICON PROXY (bypasses CORS)
// ═══════════════════════════════════════
add_action('wp_ajax_chromacode_favicon', 'chromacode_favicon_proxy');
add_action('wp_ajax_nopriv_chromacode_favicon', 'chromacode_favicon_proxy');

function chromacode_favicon_proxy() {
    $domain = sanitize_text_field($_GET['domain'] ?? '');
    if (!$domain) { wp_send_json_error('No domain'); return; }
    
    $sources = [
        "https://t1.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=TYPE,SIZE,URL&url=https://{$domain}&size=256",
        "https://www.google.com/s2/favicons?domain={$domain}&sz=256",
        "https://icons.duckduckgo.com/ip3/{$domain}.ico",
        "https://{$domain}/apple-touch-icon.png",
        "https://{$domain}/favicon.ico",
    ];
    
    foreach ($sources as $url) {
        $response = wp_remote_get($url, ['timeout' => 5, 'sslverify' => false]);
        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $type = wp_remote_retrieve_header($response, 'content-type');
            if ($code === 200 && strlen($body) > 100) {
                if (!$type || strpos($type, 'text/html') !== false) continue;
                $base64 = base64_encode($body);
                $mime = $type ?: 'image/png';
                wp_send_json_success(['src' => "data:{$mime};base64,{$base64}"]);
                return;
            }
        }
    }
    
    wp_send_json_error('No favicon found');
}

// Also proxy arbitrary image URLs
add_action('wp_ajax_chromacode_fetch_image', 'chromacode_fetch_image_proxy');
add_action('wp_ajax_nopriv_chromacode_fetch_image', 'chromacode_fetch_image_proxy');

function chromacode_fetch_image_proxy() {
    $url = esc_url_raw($_GET['url'] ?? '');
    if (!$url) { wp_send_json_error('No URL'); return; }
    
    $response = wp_remote_get($url, ['timeout' => 8, 'sslverify' => false]);
    if (is_wp_error($response)) { wp_send_json_error('Fetch failed'); return; }
    
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $type = wp_remote_retrieve_header($response, 'content-type');
    
    if ($code === 200 && strlen($body) > 100 && strpos($type, 'image') !== false) {
        wp_send_json_success(['src' => "data:{$type};base64," . base64_encode($body)]);
    } else {
        wp_send_json_error('Not an image');
    }
}

// ═══════════════════════════════════════
// ADMIN SETTINGS
// ═══════════════════════════════════════
add_action('admin_menu', function() {
    add_options_page(
        'ChromaCode Settings',
        'ChromaCode',
        'manage_options',
        'chromacode-settings',
        'chromacode_settings_page'
    );
});

add_action('admin_init', function() {
    register_setting('chromacode_options', 'chromacode_settings', 'chromacode_sanitize');

    // Colors section
    add_settings_section('chromacode_colors', 'Colors & Theme', null, 'chromacode-settings');
    $color_fields = [
        'bg_color'      => ['Background', '#0a0a0b'],
        'surface_color'  => ['Surface / Cards', '#141416'],
        'border_color'   => ['Borders', '#2a2a30'],
        'text_color'     => ['Text', '#e8e8ec'],
        'text_dim_color' => ['Dimmed Text', '#8888a0'],
        'accent_color'   => ['Accent (Primary)', '#ff4d4d'],
        'accent2_color'  => ['Accent (Secondary)', '#ff7043'],
    ];
    foreach ($color_fields as $key => $info) {
        add_settings_field("chromacode_$key", $info[0], function() use ($key, $info) {
            $opts = get_option('chromacode_settings', []);
            $val = $opts[$key] ?? $info[1];
            echo "<input type='color' name='chromacode_settings[$key]' value='" . esc_attr($val) . "'>";
            echo "<code style='margin-left:8px'>$info[1]</code>";
        }, 'chromacode-settings', 'chromacode_colors');
    }

    // Texts section
    add_settings_section('chromacode_texts', 'Labels & Text', null, 'chromacode-settings');
    $text_fields = [
        'title'            => ['Title', 'CHROMACODE'],
        'subtitle'         => ['Subtitle', 'Brand-aware QR codes by IT Linden'],
        'url_placeholder'  => ['URL Placeholder', 'https://itlinden.lv'],
        'btn_generate'     => ['Generate Button', 'Generate'],
        'btn_png'          => ['PNG Button', '↓ PNG'],
        'btn_svg'          => ['SVG Button', '↓ SVG'],
        'tab_favicon'      => ['Favicon Tab', 'Favicon'],
        'tab_upload'       => ['Upload Tab', 'Upload'],
        'tab_imgurl'       => ['Image URL Tab', 'Image URL'],
        'upload_text'      => ['Upload Prompt', 'Drop image or tap to browse'],
        'settings_btn'     => ['Settings Toggle', '⚙ Advanced Settings'],
        'status_initial'   => ['Initial Status', 'Enter a URL, pick image source, then Generate'],
        'white_bg_warning' => ['White BG Warning', '⚠ Image is mostly white — crop tightly around the logo for best results'],
    ];
    foreach ($text_fields as $key => $info) {
        add_settings_field("chromacode_$key", $info[0], function() use ($key, $info) {
            $opts = get_option('chromacode_settings', []);
            $val = $opts[$key] ?? $info[1];
            echo "<input type='text' name='chromacode_settings[$key]' value='" . esc_attr($val) . "' class='regular-text'>";
        }, 'chromacode-settings', 'chromacode_texts');
    }
});

function chromacode_sanitize($input) {
    $clean = [];
    foreach ($input as $key => $val) {
        if (strpos($key, 'color') !== false) {
            $clean[$key] = sanitize_hex_color($val) ?: $val;
        } else {
            $clean[$key] = sanitize_text_field($val);
        }
    }
    return $clean;
}

function chromacode_settings_page() {
    ?>
    <div class="wrap">
        <h1>ChromaCode Settings</h1>
        <p>Customize the ChromaCode QR generator appearance. Use shortcode <code>[chromacode]</code> to embed.</p>
        <form method="post" action="options.php">
            <?php
            settings_fields('chromacode_options');
            do_settings_sections('chromacode-settings');
            submit_button();
            ?>
        </form>
        <hr>
        <h2>Usage</h2>
        <p>Add <code>[chromacode]</code> to any page or post to display the QR generator.</p>
        <p>You can also use <code>[chromacode title="My QR Tool" subtitle="Custom subtitle"]</code> to override settings per-instance.</p>
    </div>
    <?php
}

// ═══════════════════════════════════════
// GET SETTINGS WITH DEFAULTS
// ═══════════════════════════════════════
function chromacode_get($key, $default = '') {
    static $opts = null;
    if ($opts === null) $opts = get_option('chromacode_settings', []);
    $defaults = [
        'bg_color' => '#0a0a0b', 'surface_color' => '#141416', 'border_color' => '#2a2a30',
        'text_color' => '#e8e8ec', 'text_dim_color' => '#8888a0',
        'accent_color' => '#ff4d4d', 'accent2_color' => '#ff7043',
        'title' => 'CHROMACODE', 'subtitle' => 'Brand-aware QR codes by IT Linden',
        'url_placeholder' => 'https://itlinden.lv', 'btn_generate' => 'Generate',
        'btn_png' => '↓ PNG', 'btn_svg' => '↓ SVG',
        'tab_favicon' => 'Favicon', 'tab_upload' => 'Upload', 'tab_imgurl' => 'Image URL',
        'upload_text' => 'Drop image or tap to browse',
        'settings_btn' => '⚙ Advanced Settings',
        'status_initial' => 'Enter a URL, pick image source, then Generate',
        'white_bg_warning' => '⚠ Image is mostly white — crop tightly around the logo for best results',
    ];
    return $opts[$key] ?? $defaults[$key] ?? $default;
}

// ═══════════════════════════════════════
// ENQUEUE ASSETS
// ═══════════════════════════════════════
add_action('wp_enqueue_scripts', function() {
    // Only load when shortcode is used (detected via has_shortcode or always load lightweight check)
});

// ═══════════════════════════════════════
// SHORTCODE
// ═══════════════════════════════════════
add_shortcode('chromacode', function($atts) {
    $atts = shortcode_atts([
        'title' => chromacode_get('title'),
        'subtitle' => chromacode_get('subtitle'),
    ], $atts);

    // Colors as CSS vars
    $vars = [
        '--cc-bg' => chromacode_get('bg_color'),
        '--cc-s' => chromacode_get('surface_color'),
        '--cc-s2' => chromacode_get('surface_color') . '20',
        '--cc-bd' => chromacode_get('border_color'),
        '--cc-t' => chromacode_get('text_color'),
        '--cc-td' => chromacode_get('text_dim_color'),
        '--cc-ac' => chromacode_get('accent_color'),
        '--cc-ac2' => chromacode_get('accent2_color'),
    ];
    $css_vars = '';
    foreach ($vars as $k => $v) $css_vars .= "$k:$v;";

    // Text strings as JS object
    $texts = json_encode([
        'generate' => chromacode_get('btn_generate'),
        'png' => chromacode_get('btn_png'),
        'svg' => chromacode_get('btn_svg'),
        'favicon' => chromacode_get('tab_favicon'),
        'upload' => chromacode_get('tab_upload'),
        'imgurl' => chromacode_get('tab_imgurl'),
        'uploadText' => chromacode_get('upload_text'),
        'settingsBtn' => chromacode_get('settings_btn'),
        'statusInitial' => chromacode_get('status_initial'),
        'whiteBgWarn' => chromacode_get('white_bg_warning'),
        'urlPlaceholder' => chromacode_get('url_placeholder'),
        'urlError' => 'Enter the URL the QR code should link to',
    ]);

    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <div id="chromacode-root" style="<?php echo esc_attr($css_vars); ?>">
    <style>
    #chromacode-root{font-family:'Outfit',sans-serif!important;background:var(--cc-bg)!important;color:var(--cc-t)!important;padding:32px 20px!important;border-radius:16px;max-width:720px;margin:0 auto;position:relative;z-index:1;isolation:isolate}
    #chromacode-root, #chromacode-root *{box-sizing:border-box!important}
    #chromacode-root p, #chromacode-root h2, #chromacode-root label, #chromacode-root span, #chromacode-root div{margin:0;padding:0;line-height:normal}
    @import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Outfit:wght@300;400;500;600;700&display=swap');
    #chromacode-root *{box-sizing:border-box;margin:0;padding:0}
    #chromacode-root .cc-header{text-align:center;margin-bottom:36px}
    #chromacode-root .cc-header h2{font-family:'Space Mono',monospace;font-size:1.8rem;font-weight:700;letter-spacing:-1px;background:linear-gradient(135deg,var(--cc-ac),var(--cc-ac2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:6px}
    #chromacode-root .cc-header p{color:var(--cc-td);font-size:.9rem;font-weight:300}
    #chromacode-root .cc-url-row{display:flex;gap:8px;margin-bottom:16px}
    #chromacode-root .cc-url-row input{flex:1;background:var(--cc-s);border:1px solid var(--cc-bd);border-radius:12px;padding:14px 16px;font-family:'Space Mono',monospace;font-size:.85rem;color:var(--cc-t);outline:none}
    #chromacode-root .cc-url-row input:focus{border-color:var(--cc-ac);box-shadow:0 0 0 3px rgba(255,77,77,.15)}
    #chromacode-root .cc-btn{background:linear-gradient(135deg,var(--cc-ac),var(--cc-ac2));border:none;border-radius:12px;padding:14px 28px;color:#fff;font-family:'Outfit',sans-serif;font-weight:600;font-size:.9rem;cursor:pointer;transition:transform .15s;white-space:nowrap}
    #chromacode-root .cc-btn:hover{transform:translateY(-1px)}
    #chromacode-root .cc-btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
    #chromacode-root .cc-tabs{display:flex;gap:2px;background:var(--cc-s);border-radius:10px;padding:3px;margin-bottom:20px}
    #chromacode-root .cc-tab{flex:1;text-align:center;padding:7px;font-size:.78rem;font-weight:500;border-radius:8px;cursor:pointer;color:var(--cc-td);transition:all .2s;border:none;background:none}
    #chromacode-root .cc-tab.on{background:var(--cc-ac);color:#fff}
    #chromacode-root .cc-tb{display:none}#chromacode-root .cc-tb.on{display:block}
    #chromacode-root .cc-dz{border:2px dashed var(--cc-bd);border-radius:12px;padding:20px;text-align:center;cursor:pointer;color:var(--cc-td);font-size:.82rem;margin-bottom:16px;transition:all .2s}
    #chromacode-root .cc-dz:hover{border-color:var(--cc-ac);background:rgba(255,77,77,.08)}
    #chromacode-root .cc-dz input{display:none}
    #chromacode-root .cc-dz .cc-th{max-width:60px;max-height:60px;border-radius:6px;margin-bottom:6px}
    #chromacode-root .cc-hint{font-size:.7rem;color:var(--cc-td);margin-top:4px}
    #chromacode-root .cc-st{font-size:.8rem;color:var(--cc-td);font-family:'Space Mono',monospace;text-align:center;min-height:1.4em;margin:12px 0}
    #chromacode-root .cc-st.err{color:var(--cc-ac)}
    #chromacode-root .cc-preview{background:#fff;border-radius:14px;padding:16px;box-shadow:0 4px 24px rgba(0,0,0,.15);margin:0 auto 20px;max-width:500px}
    #chromacode-root .cc-preview canvas{display:block;width:100%;height:auto}
    #chromacode-root .cc-carousel{display:flex;gap:10px;overflow-x:auto;padding:8px 0 16px;-webkit-overflow-scrolling:touch}
    #chromacode-root .cc-vcard{flex:0 0 auto;width:110px;cursor:pointer;transition:transform .2s}
    #chromacode-root .cc-vcard.sel{transform:scale(1.05)}
    #chromacode-root .cc-vcard .cc-vthumb{background:#fff;border-radius:10px;padding:6px;border:2px solid transparent;transition:border-color .2s}
    #chromacode-root .cc-vcard.sel .cc-vthumb{border-color:var(--cc-ac)}
    #chromacode-root .cc-vcard .cc-vthumb canvas{display:block;width:100%;height:auto;border-radius:6px}
    #chromacode-root .cc-vcard .cc-vlabel{text-align:center;font-family:'Space Mono',monospace;font-size:.7rem;color:var(--cc-td);margin-top:6px}
    #chromacode-root .cc-vcard.sel .cc-vlabel{color:var(--cc-ac);font-weight:700}
    #chromacode-root .cc-si{display:flex;align-items:center;gap:10px;padding:10px 16px;background:var(--cc-s);border-radius:10px;font-size:.82rem;color:var(--cc-td);margin-bottom:16px}
    #chromacode-root .cc-si img{width:36px;height:36px;border-radius:6px;object-fit:contain}
    #chromacode-root .cc-pal{display:flex;gap:3px;margin-left:auto}
    #chromacode-root .cc-sw{width:14px;height:14px;border-radius:3px;border:1px solid var(--cc-bd)}
    #chromacode-root .cc-dl-row{display:flex;gap:8px;justify-content:center;margin-bottom:24px}
    #chromacode-root .cc-bdl{background:var(--cc-s);border:1px solid var(--cc-bd);border-radius:10px;padding:10px 24px;color:var(--cc-t);font-family:'Outfit',sans-serif;font-weight:500;font-size:.85rem;cursor:pointer;transition:all .2s}
    #chromacode-root .cc-bdl:hover{border-color:var(--cc-ac);color:var(--cc-ac)}
    #chromacode-root .cc-settings-toggle{text-align:center;margin-bottom:16px}
    #chromacode-root .cc-settings-toggle button{background:none;border:1px solid var(--cc-bd);border-radius:8px;padding:8px 16px;color:var(--cc-td);font-size:.78rem;cursor:pointer;font-family:'Outfit',sans-serif}
    #chromacode-root .cc-sp{display:none;background:var(--cc-s);border:1px solid var(--cc-bd);border-radius:14px;padding:20px;margin-bottom:20px}
    #chromacode-root .cc-sp.open{display:block}
    #chromacode-root .cc-sp label{display:block;font-size:.82rem;color:var(--cc-td);margin-bottom:4px}
    #chromacode-root .cc-g2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    #chromacode-root .cc-g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
    #chromacode-root .cc-ig{margin-bottom:14px}
    #chromacode-root .cc-sp select,#chromacode-root .cc-sp input[type=color]{background:var(--cc-s);border:1px solid var(--cc-bd);border-radius:8px;padding:8px 10px;font-size:.8rem;color:var(--cc-t);outline:none;width:100%;cursor:pointer}
    #chromacode-root .cc-sp input[type=range]{-webkit-appearance:none;height:5px;padding:0;border:none;border-radius:3px;background:var(--cc-bd);margin-top:6px;width:100%}
    #chromacode-root .cc-sp input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:16px;height:16px;border-radius:50%;background:var(--cc-ac);cursor:pointer;border:2px solid var(--cc-s)}
    #chromacode-root .cc-sp input[type=color]{height:34px;padding:3px}
    #chromacode-root .cc-rv{font-family:'Space Mono',monospace;font-size:.72rem;color:var(--cc-ac)}
    #chromacode-root .cc-sep{border:none;border-top:1px solid var(--cc-bd);margin:14px 0}
    #chromacode-root .cc-chk{display:flex;align-items:center;gap:6px;font-size:.8rem;color:var(--cc-td);cursor:pointer}
    #chromacode-root .cc-chk input{accent-color:var(--cc-ac);width:15px;height:15px}
    #chromacode-root .cc-spin{width:18px;height:18px;border:2px solid var(--cc-bd);border-top-color:var(--cc-ac);border-radius:50%;animation:ccspin .6s linear infinite;display:inline-block}
    @keyframes ccspin{to{transform:rotate(360deg)}}
    #chromacode-root input[type=text]{background:var(--cc-s);border:1px solid var(--cc-bd);border-radius:10px;padding:10px 14px;font-family:'Space Mono',monospace;font-size:.8rem;color:var(--cc-t);outline:none;width:100%}
    </style>

    <div class="cc-header">
        <h2><?php echo esc_html($atts['title']); ?></h2>
        <p><?php echo esc_html($atts['subtitle']); ?></p>
    </div>

    <div class="cc-url-row">
        <input type="text" id="cc-url" placeholder="<?php echo esc_attr(chromacode_get('url_placeholder')); ?>" spellcheck="false">
        <button class="cc-btn" id="cc-bg" onclick="ccGenerate()"><?php echo esc_html(chromacode_get('btn_generate')); ?></button>
    </div>

    <div class="cc-tabs" id="cc-tabs">
        <button class="cc-tab on" data-t="fav"><?php echo esc_html(chromacode_get('tab_favicon')); ?></button>
        <button class="cc-tab" data-t="upl"><?php echo esc_html(chromacode_get('tab_upload')); ?></button>
        <button class="cc-tab" data-t="iu"><?php echo esc_html(chromacode_get('tab_imgurl')); ?></button>
    </div>
    <div id="cc-t-fav" class="cc-tb on"></div>
    <div id="cc-t-upl" class="cc-tb">
        <div class="cc-dz" id="cc-dz"><div id="cc-dp"><?php echo esc_html(chromacode_get('upload_text')); ?></div><input type="file" id="cc-fi" accept="image/*"></div>
    </div>
    <div id="cc-t-iu" class="cc-tb"><input type="text" id="cc-iu" placeholder="https://example.com/logo.png"></div>

    <div id="cc-stm" class="cc-st"><?php echo esc_html(chromacode_get('status_initial')); ?></div>
    <div id="cc-si" class="cc-si" style="display:none"><img id="cc-sth"><span id="cc-sn">—</span><div class="cc-pal" id="cc-pal"></div></div>

    <div id="cc-mainWrap" style="display:none">
        <div class="cc-preview"><canvas id="cc-mainCanvas" width="1024" height="1024"></canvas></div>
        <div class="cc-carousel" id="cc-carousel"></div>
        <div class="cc-dl-row">
            <button class="cc-bdl" onclick="ccDlPNG()"><?php echo esc_html(chromacode_get('btn_png')); ?></button>
            <button class="cc-bdl" onclick="ccDlSVG()"><?php echo esc_html(chromacode_get('btn_svg')); ?></button>
        </div>
    </div>

    <div class="cc-settings-toggle"><button onclick="document.getElementById('cc-sp').classList.toggle('open')"><?php echo esc_html(chromacode_get('settings_btn')); ?></button></div>
    <div id="cc-sp" class="cc-sp">
        <div class="cc-ig"><div class="cc-g3">
            <div><label>Shape</label><select id="cc-shp"><option value="circle">Circle</option><option value="rrect">Rounded</option><option value="sq">Square</option></select></div>
            <div><label>ECC</label><select id="cc-ecc"><option value="H" selected>High</option><option value="Q">Quartile</option><option value="M">Medium</option></select></div>
            <div><label>Output px</label><select id="cc-osz"><option value="800">800</option><option value="1024" selected>1024</option><option value="1600">1600</option><option value="2048">2048</option></select></div>
        </div></div>
        <div class="cc-sep"></div>
        <div class="cc-ig"><label>Dark Dot Range — <span class="cc-rv" id="cc-vDmn">30%</span> to <span class="cc-rv" id="cc-vDmx">105%</span></label>
            <div class="cc-g2"><div><input type="range" id="cc-sDmn" min="15" max="60" value="30"></div><div><input type="range" id="cc-sDmx" min="70" max="110" value="105"></div></div></div>
        <div class="cc-ig"><label>Ghost Strength — <span class="cc-rv" id="cc-vG">80%</span></label><input type="range" id="cc-sG" min="0" max="80" value="80"></div>
        <div class="cc-ig"><label>Ghost Threshold — <span class="cc-rv" id="cc-vGt">8%</span></label><input type="range" id="cc-sGt" min="0" max="60" value="8"></div>
        <div class="cc-sep"></div>
        <div class="cc-ig"><div class="cc-g2">
            <div><label>Background</label><input type="color" id="cc-bgc" value="#ffffff"></div>
            <div><label>Fallback dark</label><input type="color" id="cc-fdc" value="#000000"></div>
        </div></div>
        <div class="cc-ig"><label class="cc-chk"><input type="checkbox" id="cc-col" checked> Use image colors</label></div>
    </div>
    </div>

    <script>
    (function(){
    const TEXTS = <?php echo $texts; ?>;
    const AJAX_URL = '<?php echo admin_url("admin-ajax.php"); ?>';
    const VERSIONS = [7, 10, 13, 15, 18];
    let S = { versions: [], selectedIdx: 2, img: null, colors: [], label: '', thumb: '' };
    let uploadedFile = null;
    const $=id=>document.getElementById('cc-'+id);

    // Tabs
    document.getElementById('cc-tabs').onclick = e => {
        const t = e.target.closest('.cc-tab'); if (!t) return;
        document.querySelectorAll('#chromacode-root .cc-tab').forEach(x => x.classList.remove('on'));
        t.classList.add('on');
        document.querySelectorAll('#chromacode-root .cc-tb').forEach(x => x.classList.remove('on'));
        document.getElementById('cc-t-' + t.dataset.t).classList.add('on');
    };

    // File upload
    const dz=$('dz'),fi=$('fi');
    dz.onclick=()=>fi.click();
    dz.ondragover=e=>{e.preventDefault();dz.classList.add('ov')};
    dz.ondragleave=()=>dz.classList.remove('ov');
    dz.ondrop=e=>{e.preventDefault();dz.classList.remove('ov');pickFile(e.dataTransfer.files[0])};
    fi.onchange=e=>pickFile(e.target.files[0]);
    function pickFile(f){if(!f||!f.type.startsWith('image/'))return;uploadedFile=f;const r=new FileReader();r.onload=e=>$('dp').innerHTML=`<img class="cc-th" src="${e.target.result}"><br><span style="font-size:.7rem">${f.name}</span>`;r.readAsDataURL(f)}

    function status(m,err){const e=$('stm');e.textContent=m;e.className='cc-st'+(err?' err':'')}
    function activeTab(){return document.querySelector('#chromacode-root .cc-tab.on').dataset.t}

    // Image loading — uses server-side proxy to bypass CORS
    async function loadImg(url){
        const tab=activeTab();
        if(tab==='upl'){if(!uploadedFile)throw 'No image uploaded.';return await fileToImg(uploadedFile)}
        if(tab==='iu'){
            const u=$('iu').value.trim();if(!u)throw 'Enter an image URL.';
            // Try server proxy first, then direct
            const proxyImg = await fetchViaProxy(u);
            if (proxyImg) return proxyImg;
            return await fetchImg(u)||(()=>{throw 'Cannot load image.'})();
        }
        // Favicon via server proxy
        let d;try{d=new URL(url).hostname}catch{throw 'Invalid URL'}
        const proxyImg = await fetchFavicon(d);
        if (proxyImg) return proxyImg;
        // Fallback to client-side attempts
        for(const s of[`https://t1.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=TYPE,SIZE,URL&url=https://${d}&size=256`,`https://www.google.com/s2/favicons?domain=${d}&sz=256`]){
            const img=await fetchImg(s);if(img)return img;
        }
        throw `Cannot load favicon for ${d}. Use Upload tab.`;
    }
    async function fetchFavicon(domain) {
        try {
            const resp = await fetch(AJAX_URL + '?action=chromacode_favicon&domain=' + encodeURIComponent(domain));
            const json = await resp.json();
            if (json.success && json.data?.src) {
                return await dataUrlToImg(json.data.src);
            }
        } catch {}
        return null;
    }
    async function fetchViaProxy(imgUrl) {
        try {
            const resp = await fetch(AJAX_URL + '?action=chromacode_fetch_image&url=' + encodeURIComponent(imgUrl));
            const json = await resp.json();
            if (json.success && json.data?.src) {
                return await dataUrlToImg(json.data.src);
            }
        } catch {}
        return null;
    }
    function dataUrlToImg(src) {
        return new Promise((res, rej) => {
            const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = src;
        });
    }
    function fileToImg(f){return new Promise((res,rej)=>{const r=new FileReader();r.onload=()=>{const i=new Image();i.onload=()=>res(i);i.onerror=rej;i.src=r.result};r.onerror=rej;r.readAsDataURL(f)})}
    function fetchImg(src){return new Promise(res=>{fetch(src,{signal:AbortSignal.timeout(5000)}).then(r=>r.ok?r.blob():null).then(b=>{if(!b||b.size<50){res(null);return}const u=URL.createObjectURL(b);const i=new Image();i.onload=()=>i.naturalWidth<2?res(null):res(i);i.onerror=()=>{URL.revokeObjectURL(u);res(null)};i.src=u}).catch(()=>res(null))})}

    // Helpers
    function extractColors(img){const c=document.createElement('canvas');c.width=c.height=64;const ctx=c.getContext('2d');ctx.drawImage(img,0,0,64,64);const d=ctx.getImageData(0,0,64,64).data;const bk={};for(let i=0;i<d.length;i+=4){if(d[i+3]<128)continue;const k=`${Math.round(d[i]/24)*24},${Math.round(d[i+1]/24)*24},${Math.round(d[i+2]/24)*24}`;bk[k]=(bk[k]||0)+1}return Object.entries(bk).sort((a,b)=>b[1]-a[1]).slice(0,8).map(([k])=>{const[r,g,b]=k.split(',').map(Number);return{r,g,b}})}
    function imgToGrid(img,sz){const c=document.createElement('canvas');c.width=c.height=sz;const ctx=c.getContext('2d');const iw=img.naturalWidth,ih=img.naturalHeight,sc=Math.max(sz/iw,sz/ih),sw=iw*sc,sh=ih*sc;ctx.drawImage(img,(sz-sw)/2,(sz-sh)/2,sw,sh);return ctx.getImageData(0,0,sz,sz)}
    function px(g,c,r){const i=(r*g.width+c)*4;return{r:g.data[i],g:g.data[i+1],b:g.data[i+2],a:g.data[i+3]}}
    function lum(r,g,b){return(0.299*r+0.587*g+0.114*b)/255}
    function isFinder(r,c,sz){const z=[[0,0],[0,sz-7],[sz-7,0]];for(const[pr,pc]of z)if(r>=pr&&r<pr+7&&c>=pc&&c<pc+7)return true;return false}
    function isCritical(r,c,sz){if(isFinder(r,c,sz))return true;if((r===7&&(c<8||c>=sz-8))||(r===sz-8&&c<8))return true;if((c===7&&(r<8||r>=sz-8))||(c===sz-8&&r<8))return true;return false}
    function hexRgb(h){const m=h.match(/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i);return m?{r:+('0x'+m[1]),g:+('0x'+m[2]),b:+('0x'+m[3])}:{r:0,g:0,b:0}}
    function boostColor(r,g,b,amount){const gray=0.299*r+0.587*g+0.114*b;return{r:Math.min(255,Math.max(0,Math.round(gray+(r-gray)*(1+amount)))),g:Math.min(255,Math.max(0,Math.round(gray+(g-gray)*(1+amount)))),b:Math.min(255,Math.max(0,Math.round(gray+(b-gray)*(1+amount))))}}

    function autoTune(grid,sz){
        let sumL=0,darkPx=0,lightPx=0,minL=1,maxL=0;
        for(let r=0;r<sz;r++)for(let c=0;c<sz;c++){const p=px(grid,c,r);const L=lum(p.r,p.g,p.b);sumL+=L;if(L<.25)darkPx++;if(L>.75)lightPx++;if(L<minL)minL=L;if(L>maxL)maxL=L}
        const total=sz*sz,contrast=maxL-minL,lightR=lightPx/total,darkR=darkPx/total;
        let dm,dx,gh,gt;
        if(contrast>.6){dm=30;dx=105;gh=80;gt=8}else if(contrast>.35){dm=33;dx=100;gh=70;gt=15}else{dm=38;dx=95;gh=60;gt=22}
        if(lightR>.5){gh=Math.min(80,gh+8);dm=Math.max(20,dm-3)}
        if(darkR>.5){gh=Math.max(45,gh-8);gt=Math.min(30,gt+6)}
        setS('sDmn',dm,'vDmn');setS('sDmx',dx,'vDmx');setS('sG',gh,'vG');setS('sGt',gt,'vGt');
    }
    function setS(id,v,lid){$( id).value=v;$(lid).textContent=v+'%'}

    // Generate
    window.ccGenerate = async function(){
        let url=$('url').value.trim();
        if(!url){status(TEXTS.urlError,true);return}
        if(!/^https?:\/\//i.test(url)&&url.includes('.')){url='https://'+url;$('url').value=url}
        const btn=$('bg');btn.disabled=true;btn.innerHTML='<span class="cc-spin"></span>';
        status('Loading image...');
        try{
            const sourceImg=await loadImg(url);
            const colors=extractColors(sourceImg);
            S.colors=colors;S.label=(()=>{try{return new URL(url).hostname}catch{return'qr'}})();S.thumb=sourceImg.src||'';
            $('sth').src=S.thumb;$('sn').textContent=S.label;
            const pal=$('pal');pal.innerHTML='';
            colors.forEach(c=>{const d=document.createElement('div');d.className='cc-sw';d.style.background=`rgb(${c.r},${c.g},${c.b})`;pal.appendChild(d)});
            $('si').style.display='flex';
            const ecc=$('ecc').value;S.versions=[];
            const carousel=$('carousel');carousel.innerHTML='';
            let tuned=false;
            for(let i=0;i<VERSIONS.length;i++){
                const ver=VERSIONS[i];let qr;
                try{qr=qrcode(ver,ecc);qr.addData(url);qr.make()}catch{try{qr=qrcode(0,ecc);qr.addData(url);qr.make()}catch{continue}}
                const sz=qr.getModuleCount(),grid=imgToGrid(sourceImg,sz);
                if(!tuned){autoTune(grid,sz);tuned=true;
                    let lc=0;for(let r=0;r<sz;r++)for(let c=0;c<sz;c++){const pp=px(grid,c,r);if(lum(pp.r,pp.g,pp.b)>.85)lc++}
                    if(lc/(sz*sz)>.6)status(TEXTS.whiteBgWarn,false);
                }
                const tc=document.createElement('canvas');tc.width=tc.height=256;
                renderToCanvas(tc,qr,sz,grid,256);
                S.versions.push({qr,sz,grid,ver,modules:sz});
                const card=document.createElement('div');card.className='cc-vcard'+(i===S.selectedIdx?' sel':'');
                card.innerHTML=`<div class="cc-vthumb"></div><div class="cc-vlabel">v${ver} · ${sz}px</div>`;
                card.querySelector('.cc-vthumb').appendChild(tc);
                card.onclick=(()=>{const idx=i;return()=>selectVersion(idx)})();
                carousel.appendChild(card);
            }
            S.selectedIdx=Math.min(S.selectedIdx,S.versions.length-1);
            renderSelected();$('mainWrap').style.display='block';status('');
        }catch(e){status('Error: '+e,true);console.error(e)}
        finally{btn.disabled=false;btn.textContent=TEXTS.generate}
    };

    function selectVersion(idx){S.selectedIdx=idx;document.querySelectorAll('#chromacode-root .cc-vcard').forEach((c,i)=>c.classList.toggle('sel',i===idx));renderSelected()}
    function renderSelected(){const v=S.versions[S.selectedIdx];if(!v)return;const outPx=+$('osz').value;const canvas=$('mainCanvas');canvas.width=canvas.height=outPx;renderToCanvas(canvas,v.qr,v.sz,v.grid,outPx)}

    // Core render
    function renderToCanvas(canvas,qr,sz,grid,outPx){
        const darkMin=+$('sDmn').value/100,darkMax=+$('sDmx').value/100,ghostStr=+$('sG').value/100,ghostThresh=+$('sGt').value/100;
        const shape=$('shp').value,bgColor=$('bgc').value,fallbackDark=hexRgb($('fdc').value),useColor=$('col').checked;
        const ctx=canvas.getContext('2d'),quiet=2,total=sz+quiet*2,cell=outPx/total,maxR=cell/2;
        ctx.fillStyle=bgColor;ctx.fillRect(0,0,outPx,outPx);
        for(let row=0;row<sz;row++){for(let col=0;col<sz;col++){
            const isDark=qr.isDark(row,col),cx=(col+quiet+.5)*cell,cy=(row+quiet+.5)*cell;
            const critical=isCritical(row,col,sz),p=px(grid,col,row),L=lum(p.r,p.g,p.b),darkness=1-L;
            if(isDark){let r,color;
                if(critical){r=maxR*.92;const dom=S.colors[0]||fallbackDark;const fc=useColor?{r:Math.round(dom.r*.8),g:Math.round(dom.g*.8),b:Math.round(dom.b*.8)}:fallbackDark;color=`rgb(${fc.r},${fc.g},${fc.b})`}
                else{r=maxR*Math.max(darkMin,Math.min(1.05,darkMin+(darkMax-darkMin)*darkness));
                    if(useColor&&p.a>100){const bc=boostColor(p.r,p.g,p.b,0.3);if(L>.78){const mix=(L-.78)/.22;color=`rgb(${Math.round(bc.r*(1-mix*.75)+fallbackDark.r*mix*.75)},${Math.round(bc.g*(1-mix*.75)+fallbackDark.g*mix*.75)},${Math.round(bc.b*(1-mix*.75)+fallbackDark.b*mix*.75)})`}else{color=`rgb(${bc.r},${bc.g},${bc.b})`}}
                    else{color=`rgb(${fallbackDark.r},${fallbackDark.g},${fallbackDark.b})`}}
                dot(ctx,cx,cy,r,shape,color);
            }else if(!critical&&ghostStr>0&&darkness>ghostThresh){
                const nd=(darkness-ghostThresh)/(1-ghostThresh),ghostCeil=maxR*0.35;
                const r=Math.min(maxR*.04+(ghostCeil*ghostStr-maxR*.04)*Math.pow(nd,.55),ghostCeil);
                let color;if(useColor&&p.a>100){const bc=boostColor(p.r,p.g,p.b,0.3);color=`rgb(${bc.r},${bc.g},${bc.b})`}
                else{color=`rgb(${fallbackDark.r},${fallbackDark.g},${fallbackDark.b})`}
                dot(ctx,cx,cy,r,shape,color);
            }
        }}
    }
    function dot(ctx,cx,cy,r,shape,color){ctx.fillStyle=color;if(shape==='circle'){ctx.beginPath();ctx.arc(cx,cy,r,0,Math.PI*2);ctx.fill()}else if(shape==='rrect'){const rr=r*.35;ctx.beginPath();ctx.moveTo(cx-r+rr,cy-r);ctx.lineTo(cx+r-rr,cy-r);ctx.quadraticCurveTo(cx+r,cy-r,cx+r,cy-r+rr);ctx.lineTo(cx+r,cy+r-rr);ctx.quadraticCurveTo(cx+r,cy+r,cx+r-rr,cy+r);ctx.lineTo(cx-r+rr,cy+r);ctx.quadraticCurveTo(cx-r,cy+r,cx-r,cy+r-rr);ctx.lineTo(cx-r,cy-r+rr);ctx.quadraticCurveTo(cx-r,cy-r,cx-r+rr,cy-r);ctx.closePath();ctx.fill()}else{ctx.fillRect(cx-r,cy-r,r*2,r*2)}}

    // Live updates
    ['sDmn','sDmx','sG','sGt'].forEach(id=>{const vId={sDmn:'vDmn',sDmx:'vDmx',sG:'vG',sGt:'vGt'}[id];$(id).oninput=()=>{$(vId).textContent=$(id).value+'%';renderSelected()}});
    ['shp','osz','bgc','fdc','col'].forEach(id=>{const el=$(id);el.addEventListener('change',renderSelected);el.addEventListener('input',renderSelected)});
    $('ecc').onchange=()=>{if(S.versions.length)ccGenerate()};
    $('url').onkeydown=e=>{if(e.key==='Enter')ccGenerate()};

    // Downloads
    window.ccDlPNG=async function(){
        const c=$('mainCanvas'),v=S.versions[S.selectedIdx],name=`chromacode-${S.label}-v${v?.ver||'x'}.png`;
        try{const blob=await new Promise(r=>c.toBlob(r,'image/png'));const file=new File([blob],name,{type:'image/png'});if(navigator.canShare?.({files:[file]})){await navigator.share({files:[file],title:name});return}}catch{}
        const dataUrl=c.toDataURL('image/png');const w=window.open('');
        if(w){w.document.write(`<img src="${dataUrl}" style="max-width:100%">`);w.document.title=name}
        else{const a=document.createElement('a');a.download=name;a.href=dataUrl;document.body.appendChild(a);a.click();document.body.removeChild(a)}
    };
    window.ccDlSVG=async function(){
        const v=S.versions[S.selectedIdx];if(!v)return;
        const{qr:qrV,sz,grid}=v,darkMin=+$('sDmn').value/100,darkMax=+$('sDmx').value/100,ghostStr=+$('sG').value/100,ghostThresh=+$('sGt').value/100;
        const shape=$('shp').value,bgColor=$('bgc').value,fd=hexRgb($('fdc').value),useColor=$('col').checked;
        const quiet=2,total=sz+quiet*2,cell=12,svgSz=total*cell,maxR=cell/2;
        const parts=[`<svg xmlns="http://www.w3.org/2000/svg" width="${svgSz}" height="${svgSz}" viewBox="0 0 ${svgSz} ${svgSz}">`,`<rect width="${svgSz}" height="${svgSz}" fill="${bgColor}"/>`];
        for(let row=0;row<sz;row++){for(let col=0;col<sz;col++){
            const isDark=qrV.isDark(row,col),cx=(col+quiet+.5)*cell,cy=(row+quiet+.5)*cell;
            const critical=isCritical(row,col,sz),p=px(grid,col,row),L=lum(p.r,p.g,p.b),darkness=1-L;
            if(isDark){let r,color;if(critical){r=maxR*.92;const dom=S.colors[0]||fd;const fc=useColor?{r:Math.round(dom.r*.8),g:Math.round(dom.g*.8),b:Math.round(dom.b*.8)}:fd;color=`rgb(${fc.r},${fc.g},${fc.b})`}
            else{r=maxR*Math.max(darkMin,Math.min(1.05,darkMin+(darkMax-darkMin)*darkness));if(useColor&&p.a>100){const bc=boostColor(p.r,p.g,p.b,0.3);if(L>.78){const m=(L-.78)/.22;color=`rgb(${Math.round(bc.r*(1-m*.75)+fd.r*m*.75)},${Math.round(bc.g*(1-m*.75)+fd.g*m*.75)},${Math.round(bc.b*(1-m*.75)+fd.b*m*.75)})`}else{color=`rgb(${bc.r},${bc.g},${bc.b})`}}else{color=`rgb(${fd.r},${fd.g},${fd.b})`}}
            const svgD=shape==='circle'?`<circle cx="${cx}" cy="${cy}" r="${r}" fill="${color}"/>`:shape==='rrect'?`<rect x="${cx-r}" y="${cy-r}" width="${r*2}" height="${r*2}" rx="${r*.35}" fill="${color}"/>`:`<rect x="${cx-r}" y="${cy-r}" width="${r*2}" height="${r*2}" fill="${color}"/>`;
            parts.push(svgD)}
            else if(!critical&&ghostStr>0&&darkness>ghostThresh){const nd=(darkness-ghostThresh)/(1-ghostThresh),gc=maxR*0.35,r=Math.min(maxR*.04+(gc*ghostStr-maxR*.04)*Math.pow(nd,.55),gc);
            const bc=useColor&&p.a>100?boostColor(p.r,p.g,p.b,0.3):{r:fd.r,g:fd.g,b:fd.b};const color=`rgb(${bc.r},${bc.g},${bc.b})`;
            parts.push(shape==='circle'?`<circle cx="${cx}" cy="${cy}" r="${r}" fill="${color}"/>`:`<rect x="${cx-r}" y="${cy-r}" width="${r*2}" height="${r*2}" rx="${r*.2}" fill="${color}"/>`)}
        }}
        parts.push('</svg>');
        const name=`chromacode-${S.label}-v${v.ver}.svg`,blob=new Blob([parts.join('\n')],{type:'image/svg+xml'});
        try{const file=new File([blob],name,{type:'image/svg+xml'});if(navigator.canShare?.({files:[file]})){await navigator.share({files:[file],title:name});return}}catch{}
        const url=URL.createObjectURL(blob);const w=window.open(url);
        if(!w){const a=document.createElement('a');a.download=name;a.href=url;document.body.appendChild(a);a.click();document.body.removeChild(a)}
    };
    })();
    </script>
    <?php
    return ob_get_clean();
});
