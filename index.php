<?php
/**
 * PHP Art Gallery with Auto-Generated Previews + PayPal Buttons
 * WIP use at own risk
 * Features
 * - Auto-discovers projects in two-level folders: /gallery/<category>/<project>
 * - Each project can include:
 *     - <project>.jpg  (required for preview generation)
 *     - <project>.png  (optional)
 *     - <project>.bmp  (optional)
 *     - description.txt (optional)
 *     - <project>.preview.jpg (auto-generated on first view if missing or stale)
 * - Renders a responsive gallery with a PayPal button per project
 * - Uses PHP Imagick for high-quality preview generation
 *
 * Requirements
 * - PHP 8+
 * - imagick extension installed and enabled
 * - Web server write permission to project folders (to save previews)
 *
 * Setup
 * 1) Put this file as index.php at your web root (or in any folder).
 * 2) Create a folder named `gallery` next to it (or change $GALLERY_DIR below).
 * 3) Inside `gallery`, create categories, then project subfolders, e.g.:
 *      gallery/
 *        cartoons/
 *          project1/
 *            project1.jpg
 *            project1.png
 *            project1.bmp
 *            description.txt
 *        realistic/
 *          project2/
 *            project2.jpg
 *            description.txt
 * 4) Fill in your PayPal Client ID and default pricing below.
 */

//---------------------------------------------------------------
// Configuration
//---------------------------------------------------------------
$GALLERY_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'gallery';
$SITE_TITLE  = 'My Art Shop';
$CURRENCY    = 'USD';             // e.g., 'USD', 'EUR', 'CAD'
$DEFAULT_PRICE = 25.00;           // fallback price if no price.txt
$PREVIEW_MAX_WIDTH  = 900;        // pixels
$PREVIEW_MAX_HEIGHT = 900;        // pixels
$PAYPAL_CLIENT_ID = 'YOUR_PAYPAL_CLIENT_ID_HERE'; // TODO: replace with your live or sandbox client id

// If a project folder contains price.txt, that value overrides $DEFAULT_PRICE
// Optional: a project folder may also include a sku.txt file for your own records.

//---------------------------------------------------------------
// Utility helpers
//---------------------------------------------------------------
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function slugify(string $text): string {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    return strtolower($text ?: 'n-a');
}

function findFirstMatching(string $dir, array $patterns): ?string {
    foreach ($patterns as $pattern) {
        $matches = glob($dir . DIRECTORY_SEPARATOR . $pattern, GLOB_NOSORT);
        if ($matches && file_exists($matches[0])) return $matches[0];
    }
    return null;
}

//---------------------------------------------------------------
// Preview generation (Imagick)
//---------------------------------------------------------------
function ensurePreview(string $dir, string $projectBase, int $maxW, int $maxH): ?string {

    // We will name previews as <project>.preview.jpg
    $preview = $dir . DIRECTORY_SEPARATOR . $projectBase . '.preview.jpg';
    $source  = $dir . DIRECTORY_SEPARATOR . $projectBase . '.jpg';

    if (!file_exists($source)) {
        // Attempt to fall back to any .jpg in the folder
        $possible = glob($dir . DIRECTORY_SEPARATOR . '*.jpg');
        if ($possible) $source = $possible[0];
        if (!file_exists($source)) return null; // cannot make preview
    }

    $needsMake = !file_exists($preview) || (filemtime($source) > filemtime($preview));

    if ($needsMake) {
        if (!extension_loaded('imagick')) {
            // Graceful fallback: just copy the source as preview if Imagick missing
            @copy($source, $preview);
            return file_exists($preview) ? $preview : null;
        }
        try {
            $img = new Imagick($source);
            $img->setImageColorspace(Imagick::COLORSPACE_RGB);
            $img->setImageCompression(Imagick::COMPRESSION_JPEG);
            $img->setImageCompressionQuality(82);
            $img->stripImage();
            $img->thumbnailImage($maxW, $maxH, true, true); // best-fit within bounds
            $img->setImageFormat('jpeg');
            $img->writeImage($preview);
            $img->clear();
            $img->destroy();
        } catch (Throwable $e) {
            // Fallback again
            @copy($source, $preview);
        }
    }
    return file_exists($preview) ? $preview : null;
}

//---------------------------------------------------------------
// Project discovery
//---------------------------------------------------------------
function discoverProjects(string $root): array {
    if (!is_dir($root)) return [];
    $projects = [];

    $categories = array_filter(glob($root . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT), 'is_dir');

    foreach ($categories as $catPath) {
        $category = basename($catPath);
        $projectDirs = array_filter(glob($catPath . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT), 'is_dir');
        foreach ($projectDirs as $projPath) {
            $project = basename($projPath);
            $projectBase = $project; // expected basename for files

            // Files
            $jpg = findFirstMatching($projPath, ["$projectBase.jpg", '*.jpg']);
            $png = findFirstMatching($projPath, ["$projectBase.png", '*.png']);
            $bmp = findFirstMatching($projPath, ["$projectBase.bmp", '*.bmp']);
            $descFile = $projPath . DIRECTORY_SEPARATOR . 'description.txt';
            $priceFile = $projPath . DIRECTORY_SEPARATOR . 'price.txt';
            $skuFile   = $projPath . DIRECTORY_SEPARATOR . 'sku.txt';

            $description = file_exists($descFile) ? trim((string)@file_get_contents($descFile)) : '';
            $price = file_exists($priceFile) ? floatval(trim((string)@file_get_contents($priceFile))) : null;
            $sku   = file_exists($skuFile) ? trim((string)@file_get_contents($skuFile)) : '';

            $projects[] = [
                'category' => $category,
                'project'  => $project,
                'path'     => $projPath,
                'jpg'      => $jpg,
                'png'      => $png,
                'bmp'      => $bmp,
                'description' => $description,
                'price'    => $price,
                'sku'      => $sku,
            ];
        }
    }

    // Sort by category then project name naturally
    usort($projects, function($a, $b) {
        return [strtolower($a['category']), strtolower($a['project'])] <=> [strtolower($b['category']), strtolower($b['project'])];
    });

    return $projects;
}

$items = discoverProjects($GALLERY_DIR);

//---------------------------------------------------------------
// HTML starts
//---------------------------------------------------------------
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= h($SITE_TITLE) ?></title>
<style>
    :root{
        --bg:#0b0c10; --card:#121318; --ink:#e9eef5; --muted:#a8b3c7; --accent:#8ab4f8; --ring:#2b8cff;
    }
    *{box-sizing:border-box}
    body{margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif; background:var(--bg); color:var(--ink)}
    header{position:sticky; top:0; z-index:10; backdrop-filter:saturate(1.2) blur(8px); background:rgba(11,12,16,.7); border-bottom:1px solid #1f2330}
    .wrap{max-width:1200px; margin:0 auto; padding:18px}
    .title{display:flex; align-items:center; gap:12px}
    .title h1{font-size:clamp(1.2rem,2vw+1rem,2rem); margin:0}
    .grid{display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:16px; padding:24px 18px}
    .card{background:var(--card); border:1px solid #1a1d26; border-radius:16px; overflow:hidden; box-shadow:0 6px 20px rgba(0,0,0,.25)}
    .thumb{display:block; position:relative; aspect-ratio:1/1; overflow:hidden; background:#0d0f14}
    .thumb img{width:100%; height:100%; object-fit:cover; display:block; }
    .badge{position:absolute; top:10px; left:10px; background:rgba(10,12,18,.65); border:1px solid #2a2f3c; color:var(--muted); padding:4px 8px; border-radius:999px; font-size:.75rem}
    .body{padding:14px}
    .cat{color:var(--muted); font-size:.8rem}
    .name{font-weight:600; margin:.2rem 0 .5rem}
    .description{color:#d2d8e3; font-size:.92rem; line-height:1.35; min-height:2.6em}
    .files{display:flex; gap:10px; flex-wrap:wrap; margin:10px 0 12px}
    .chip{border:1px solid #2a2f3c; background:#121621; color:var(--ink); padding:6px 10px; border-radius:999px; text-decoration:none; font-size:.85rem}
    .foot{display:flex; align-items:center; justify-content:space-between; gap:10px}
    .price{font-weight:700}
    .btn{appearance:none; border:1px solid #2a2f3c; background:linear-gradient(180deg,#1b232f,#111622); padding:10px 14px; border-radius:12px; color:var(--ink); cursor:pointer}
    .searchbar{display:flex; gap:10px; padding:10px 18px}
    .searchbar input, .searchbar select{background:#0e1117; border:1px solid #1d2230; color:var(--ink); padding:10px 12px; border-radius:10px; width:100%}
    .empty{opacity:.7; text-align:center; padding:40px}
    footer{color:var(--muted); text-align:center; padding:24px}
    a{color:var(--accent)} a:hover{filter:brightness(1.2)}
</style>
<script>
// Simple client-side search & category filter
function filterCards(){
    const q = (document.getElementById('q').value || '').toLowerCase();
    const cat = (document.getElementById('cat').value || '');
    document.querySelectorAll('.card').forEach(c => {
        const name = c.dataset.name.toLowerCase();
        const category = c.dataset.category;
        const matchQ = !q || name.includes(q);
        const matchC = !cat || category === cat;
        c.style.display = (matchQ && matchC) ? '' : 'none';
    });
}
</script>
</head>
<body>
<header>
  <div class="wrap title">
    <h1><?= h($SITE_TITLE) ?></h1>
  </div>
  <div class="wrap searchbar">
    <input id="q" type="search" placeholder="Search projects…" oninput="filterCards()" />
    <select id="cat" onchange="filterCards()">
      <option value="">All categories</option>
      <?php
      $cats = array_values(array_unique(array_map(fn($i)=>$i['category'], $items)));
      sort($cats, SORT_NATURAL | SORT_FLAG_CASE);
      foreach ($cats as $c) echo '<option value="'.h($c).'">'.h($c)."</option>";
      ?>
    </select>
  </div>
</header>

<main class="wrap">
  <?php if (empty($items)): ?>
    <div class="empty">No projects found. Create folders like <code>gallery/cartoons/project1/</code> with <code>project1.jpg</code> inside.</div>
  <?php else: ?>
    <div class="grid">
    <?php foreach ($items as $idx => $it):
        $projectBase = $it['project'];
        $previewPath = "art" . DIRECTORY_SEPARATOR . ensurePreview($it['path'], $projectBase, $PREVIEW_MAX_WIDTH, $PREVIEW_MAX_HEIGHT);
        $previewUrl  =  $previewPath ? str_replace(__DIR__, '', $previewPath) : '';
        $jpgUrl = $it['jpg'] ? str_replace(__DIR__, '', $it['jpg']) : '';
        $pngUrl = $it['png'] ? str_replace(__DIR__, '', $it['png']) : '';
        $bmpUrl = $it['bmp'] ? str_replace(__DIR__, '', $it['bmp']) : '';
        $price = is_null($it['price']) ? $DEFAULT_PRICE : $it['price'];
        $sku   = $it['sku'] ?: ($it['category'] . '/' . $it['project']);

        $id = slugify($it['category'] . '-' . $it['project']) . '-' . $idx;
    ?>
      <article class="card" data-name="<?= h($it['project']) ?>" data-category="<?= h($it['category']) ?>">
        <a class="thumb" href="<?= h($jpgUrl ?: $pngUrl ?: $bmpUrl ?: '#') ?>" target="_blank" rel="noopener">
          <?php if ($previewUrl): ?>
            <img src="<?= h($previewUrl) ?>" alt="Preview of <?= h($it['project']) ?>" loading="lazy" />
          <?php else: ?>
            <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#7f8aa3;">No preview</div>
          <?php endif; ?>
          <span class="badge"><?= h($it['category']) ?></span>
        </a>
        <div class="body">
          <div class="cat"><?= h($it['category']) ?></div>
          <div class="name"><?= h($it['project']) ?></div>
          <div class="description"><?= nl2br(h($it['description'] ?: '')) ?></div>
          <div class="files">
            <?php if ($jpgUrl): ?><a class="chip" href="<?= h("art" . $jpgUrl) ?>" download>JPG</a><?php endif; ?>
            <?php if ($pngUrl): ?><a class="chip" href="<?= h("art" .$pngUrl) ?>" download>PNG</a><?php endif; ?>
            <?php if ($bmpUrl): ?><a class="chip" href="<?= h("art" .$bmpUrl) ?>" download>BMP</a><?php endif; ?>
          </div>
          <div class="foot">
            <div class="price"><?= h($CURRENCY) ?> <?= number_format((float)$price, 2) ?></div>
            <div id="paypal-button-<?= h($id) ?>" style="min-width:160px"></div>
          </div>
        </div>
      </article>
<script>
(function initPayPal<?= $idx ?>(){
  if (!window.paypal) { (window.__ppq = window.__ppq || []).push(initPayPal<?= $idx ?>); return; }

  const needsShipping = <?= json_encode(in_array($it['category'], ['prints','canvas'])) ?>;

  paypal.Buttons({
    style: { layout: 'horizontal', tagline: false },
    createOrder: function(data, actions) {
      const order = {
        purchase_units: [{
          amount: { value: '<?= number_format((float)$price, 2, ".", "") ?>', currency_code: '<?= h($CURRENCY) ?>' },
          description: '<?= h($it['project']) ?>',
          custom_id: '<?= h($sku) ?>'
        }]
      };
      if (needsShipping) {
        order.application_context = { shipping_preference: "GET_FROM_FILE" };
      } else {
        order.application_context = { shipping_preference: "NO_SHIPPING" };
      }
      return actions.order.create(order);
    },
    onApprove: function(data, actions) {
      return actions.order.capture().then(function(details) {
        console.log(details); // includes shipping info if collected
        alert('Thanks, ' + (details.payer.name.given_name || 'friend') + '! Order ' + data.orderID + ' is complete.');
      });
    },
    onError: function(err){
      console.error(err); 
      alert('Payment error. Please try again.');
    }
  }).render('#paypal-button-<?= h($id) ?>');
})();
</script>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<footer>
  <p>&copy; <?= date('Y') ?> <?= h($SITE_TITLE) ?>. Powered by PHP + Imagick + PayPal.</p>
</footer>

<!-- PayPal SDK (load once at end) -->
<script src="https://www.paypal.com/sdk/js?client-id=<?= urlencode($PAYPAL_CLIENT_ID) ?>&currency=<?= urlencode($CURRENCY) ?>" data-sdk-integration-source="button-factory"></script>
<script>
// If buttons attempted to initialize before SDK finished loading
if (window.__ppq) { window.__ppq.forEach(fn => { try{fn();}catch(e){console.error(e);} }); window.__ppq = []; }
</script>

</body>
</html>
