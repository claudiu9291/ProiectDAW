<?php
declare(strict_types=1);

/**
 * AgroLocal - Marketplace E-Commerce pentru Fermieri Locali
 * PHP 8.2 OOP + MongoDB + Stripe + DevSecOps (Anti-CSRF, Anti-Steganografie)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Încărcare variabile de mediu din .env (DevSecOps configuration)
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if (getenv($key) === false) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// 2. Autoloader Composer + Fallback PSR-4 pentru App\
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use App\Config\Database;
use App\Models\BioProduct;
use App\Security\CsrfGuard;
use App\Security\ImageSanitizer;
use App\Services\StripePaymentService;

// Generare CSRF Token pentru securitate
$csrfToken = CsrfGuard::generateToken();

// Stări mesaje flash
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Inițializare conexiune MongoDB
$mongoConnected = false;
$mongoError = null;
$db = null;
$products = [];

if (extension_loaded('mongodb') && class_exists(\MongoDB\Client::class)) {
    try {
        $db = Database::getDatabase();
        // Test conexiune rapid
        $db->command(['ping' => 1]);
        $mongoConnected = true;
    } catch (\Throwable $e) {
        $mongoError = $e->getMessage();
    }
} else {
    $mongoError = "Extensia PHP 'mongodb' sau pachetul composer 'mongodb/mongodb' nu sunt încărcate.";
}

// 3. Procesare Acțiuni (POST & GET)
$action = $_POST['action'] ?? $_GET['action'] ?? null;

// Acțiune: Adăugare Produs Bio de către Fermier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_product') {
    $token = $_POST['csrf_token'] ?? null;
    if (!CsrfGuard::validateToken($token)) {
        $_SESSION['flash_error'] = "Eroare securitate CSRF: Token invalid sau sesiune expirată.";
        header('Location: index.php');
        exit;
    }

    try {
        $name = trim((string)($_POST['name'] ?? ''));
        $basePrice = (float)($_POST['base_price'] ?? 0);
        $stock = (int)($_POST['stock'] ?? 0);
        $ecoCert = strtoupper(trim((string)($_POST['eco_certificate'] ?? '')));

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException("Te rugăm să încarci o imagine validă pentru produs (JPG sau PNG, max 2MB).");
        }

        // 1. Validare & instanțiere BioProduct (Verifică regex RO-ECO, lungime nume, preț strict pozitiv, stoc)
        $bioProduct = new BioProduct($name, $basePrice, $stock, $ecoCert);
        $finalPrice = $bioProduct->calculateFinalPrice();

        // 2. Curățare & De-steganografiere imagine cu ImageSanitizer
        $targetUploadsDir = __DIR__ . '/uploads';
        $sanitizer = new ImageSanitizer();
        $safeFilename = $sanitizer->sanitizeAndSave($_FILES['image'], $targetUploadsDir);

        // 3. Generare slug SEO-friendly
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        if (empty($slug)) {
            $slug = 'produs-bio-' . time();
        }

        // 4. Inserare în colecția MongoDB 'products'
        if ($mongoConnected && $db !== null) {
            $productsCollection = $db->selectCollection('products');
            $farmersCollection = $db->selectCollection('farmers');

            // Obținem sau creăm un fermier de referință
            $farmer = $farmersCollection->findOne();
            $farmerId = $farmer ? $farmer['_id'] : new \MongoDB\BSON\ObjectId();

            $insertResult = $productsCollection->insertOne([
                'farmer_id'       => $farmerId,
                'name'            => $bioProduct->getName(),
                'slug'            => $slug,
                'base_price'      => (float)$bioProduct->getBasePrice(),
                'final_price'     => (float)$finalPrice,
                'stock'           => (int)$bioProduct->getStock(),
                'eco_certificate' => $bioProduct->getCertificatEco(),
                'image_filename'  => $safeFilename,
                'created_at'      => new \MongoDB\BSON\UTCDateTime()
            ]);

            $_SESSION['flash_success'] = "Produsul bio '{$bioProduct->getName()}' a fost salvat cu succes în MongoDB! Reducerea eco de 5% a fost aplicată automat.";
        } else {
            $_SESSION['flash_success'] = "Produsul bio '{$bioProduct->getName()}' a fost validat, igienizat anti-steganografie ({$safeFilename}) și prețul calculat: {$finalPrice} RON. (Notă: Conexiunea MongoDB necesită rularea containerului de bază de date).";
        }
    } catch (\Throwable $ex) {
        $_SESSION['flash_error'] = "Eroare la adăugarea produsului: " . $ex->getMessage();
    }

    header('Location: index.php');
    exit;
}

// Acțiune: Cumpărare Produs (Inițiere Stripe Checkout & Salvare Comandă în MongoDB 'orders')
if ($action === 'buy_product') {
    $productId = $_POST['product_id'] ?? $_GET['product_id'] ?? null;
    $csrf = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? null;

    if (!CsrfGuard::validateToken($csrf)) {
        $_SESSION['flash_error'] = "Eroare securitate CSRF: Cererea de checkout a fost respinsă.";
        header('Location: index.php');
        exit;
    }

    $productToBuy = null;
    if ($mongoConnected && $db !== null && $productId) {
        try {
            $productToBuy = $db->selectCollection('products')->findOne([
                '_id' => new \MongoDB\BSON\ObjectId($productId)
            ]);
        } catch (\Throwable $e) {
            $productToBuy = null;
        }
    }

    // Fallback date demo dacă DB offline
    if (!$productToBuy) {
        $productToBuy = [
            '_id' => $productId ?? 'demo_prod_1',
            'name' => 'Miere Bio de Salcâm',
            'final_price' => 42.75,
            'eco_certificate' => 'RO-ECO-451'
        ];
    }

    $amount = (float)($productToBuy['final_price'] ?? 10.00);
    $productName = (string)($productToBuy['name'] ?? 'Produs AgroLocal');
    $ecoCert = (string)($productToBuy['eco_certificate'] ?? 'RO-ECO');
    $stripeKey = getenv('STRIPE_SECRET_KEY') ?: 'sk_test_mock_secret_key';

    try {
        $checkoutUrl = null;
        if (class_exists(\Stripe\StripeClient::class) && !str_contains($stripeKey, 'Mock')) {
            $stripeService = new StripePaymentService($stripeKey);
            $checkoutUrl = $stripeService->createCheckoutSession($amount, 'RON', [
                'product_id' => (string)$productToBuy['_id'],
                'product_name' => $productName,
                'eco_certificate' => $ecoCert
            ]);
        } else {
            // Mediu local / cheie test mock demo
            $mockSessionId = 'cs_test_' . bin2hex(random_bytes(10));
            $checkoutUrl = "index.php?status=success&session_id={$mockSessionId}&prod=" . urlencode($productName);
        }

        // Salvare în colecția MongoDB 'orders'
        if ($mongoConnected && $db !== null) {
            $db->selectCollection('orders')->insertOne([
                'product_id'   => $productToBuy['_id'],
                'product_name' => $productName,
                'total'        => $amount,
                'currency'     => 'RON',
                'stripe_url'   => $checkoutUrl,
                'status'       => 'pending',
                'created_at'   => new \MongoDB\BSON\UTCDateTime()
            ]);
        }

        header("Location: {$checkoutUrl}");
        exit;
    } catch (\Throwable $err) {
        $_SESSION['flash_error'] = "Eroare la inițierea plății Stripe: " . $err->getMessage();
        header('Location: index.php');
        exit;
    }
}

// 4. Încărcare listă produse pentru afișare
if ($mongoConnected && $db !== null) {
    try {
        $cursor = $db->selectCollection('products')->find([], ['sort' => ['created_at' => -1]]);
        $products = iterator_to_array($cursor);
    } catch (\Throwable $e) {
        $products = [];
    }
}

// Fallback date implicite demo dacă colecția este goală sau MongoDB nu este pornit
if (empty($products)) {
    $products = [
        [
            '_id' => 'prod_honey_demo',
            'name' => 'Miere Bio de Salcâm',
            'slug' => 'miere-bio-de-salcam',
            'base_price' => 45.00,
            'final_price' => 42.75,
            'stock' => 30,
            'eco_certificate' => 'RO-ECO-451',
            'image_filename' => 'default_honey.jpg',
            'farm_name' => 'Ferma Bio Hărman (Brașov)'
        ],
        [
            '_id' => 'prod_tomatoes_demo',
            'name' => 'Roșii Inimă de Bou',
            'slug' => 'rosii-inima-de-bou',
            'base_price' => 20.00,
            'final_price' => 19.00,
            'stock' => 100,
            'eco_certificate' => 'RO-ECO-102',
            'image_filename' => 'default_tomatoes.jpg',
            'farm_name' => 'Grădina Tradițională Moieciu'
        ]
    ];
}

// Construire Schema.org JSON-LD pentru SEO de top
$jsonLdProducts = [];
foreach ($products as $p) {
    $pId = (string)($p['_id'] ?? '');
    $jsonLdProducts[] = [
        '@type' => 'Product',
        'name' => $p['name'],
        'image' => 'http://localhost:8000/uploads/' . ($p['image_filename'] ?? 'default_honey.jpg'),
        'description' => "Produs ecologic românesc atestat {$p['eco_certificate']}, recoltat direct de la fermieri locali.",
        'offers' => [
            '@type' => 'Offer',
            'price' => (string)$p['final_price'],
            'priceCurrency' => 'RON',
            'availability' => ($p['stock'] > 0) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url' => 'http://localhost:8000/produs/' . ($p['slug'] ?? 'produs')
        ]
    ];
}
$schemaJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'itemListElement' => $jsonLdProducts
];

// Feedback status Stripe Checkout din URL
$paymentStatus = $_GET['status'] ?? null;
$sessionId = htmlspecialchars($_GET['session_id'] ?? '', ENT_QUOTES, 'UTF-8');
$paidProd = htmlspecialchars($_GET['prod'] ?? 'Produs Bio', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgroLocal &bull; Piața Fermierilor Români | Produse Bio Atestate Direct de la Sursă</title>
    
    <!-- Meta SEO & DevSecOps -->
    <meta name="description" content="AgroLocal conectează fermierii români cu clienții locali. Cumpără miere ecologică, legume proaspete și alimente bio RO-ECO direct din fermă, cu plată securizată Stripe.">
    <meta name="keywords" content="agrolocal, produse bio romanesti, piata fermieri, miere bio salcam, rosii inima de bou, ro-eco, stripe marketplace">
    <meta name="author" content="AgroLocal Marketplace">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="http://localhost:8000/">

    <!-- OpenGraph Social Tags -->
    <meta property="og:locale" content="ro_RO">
    <meta property="og:type" content="website">
    <meta property="og:title" content="AgroLocal &bull; Produse Bio & Locale Direct de la Fermieri">
    <meta property="og:description" content="Sprijină producătorii locali români. Alimente bio atestate RO-ECO cu reducere ecologică și livrare direct din grădină.">
    <meta property="og:url" content="http://localhost:8000/">
    <meta property="og:site_name" content="AgroLocal">
    <meta property="og:image" content="http://localhost:8000/uploads/default_honey.jpg">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="AgroLocal &bull; Piața Digitală a Fermierilor Români">
    <meta name="twitter:description" content="Cumpără direct de la producător. Alimente 100% ecologice cu certificare RO-ECO.">
    <meta name="twitter:image" content="http://localhost:8000/uploads/default_honey.jpg">

    <!-- Schema.org JSON-LD -->
    <script type="application/ld+json">
    <?= json_encode($schemaJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
    </script>

    <!-- Bootstrap 5.3 CSS & Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-green: #1b4332;
            --accent-green: #2d6a4f;
            --light-green: #52b788;
            --eco-badge: #081c15;
            --cream-bg: #f8f9fa;
            --earth-warm: #d8f3dc;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #fbfbf9;
            color: #2b2d42;
        }

        .serif-heading {
            font-family: 'Playfair Display', serif;
        }

        .navbar-brand-badge {
            background: linear-gradient(135deg, #2d6a4f 0%, #1b4332 100%);
            border-radius: 12px;
            color: white;
            padding: 6px 14px;
            box-shadow: 0 4px 15px rgba(45, 106, 79, 0.25);
        }

        .hero-banner {
            background: radial-gradient(circle at 10% 20%, rgb(240, 249, 244) 0%, rgb(253, 255, 253) 90%);
            border-bottom: 1px solid rgba(45, 106, 79, 0.1);
            position: relative;
            overflow: hidden;
        }

        .product-card {
            transition: all 0.35s cubic-bezier(0.165, 0.84, 0.44, 1);
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 20px;
            overflow: hidden;
            background: #ffffff;
        }

        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 35px -10px rgba(45, 106, 79, 0.15);
            border-color: rgba(82, 183, 136, 0.4);
        }

        .product-img-wrapper {
            position: relative;
            height: 250px;
            overflow: hidden;
            background-color: #f1f3f5;
        }

        .product-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s ease;
        }

        .product-card:hover .product-img {
            transform: scale(1.06);
        }

        .eco-ribbon {
            position: absolute;
            top: 14px;
            left: 14px;
            background: rgba(8, 28, 21, 0.85);
            backdrop-filter: blur(8px);
            color: #74c69d;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 30px;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            border: 1px solid rgba(116, 198, 157, 0.3);
        }

        .stock-badge {
            position: absolute;
            top: 14px;
            right: 14px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(6px);
            color: #1b4332;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        .price-tag {
            font-size: 1.4rem;
            font-weight: 800;
            color: #1b4332;
        }

        .old-price {
            text-decoration: line-through;
            color: #8d99ae;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .btn-agro {
            background: linear-gradient(135deg, #2d6a4f 0%, #1b4332 100%);
            color: white;
            border: none;
            font-weight: 600;
            border-radius: 12px;
            padding: 10px 20px;
            transition: all 0.25s ease;
        }

        .btn-agro:hover {
            background: linear-gradient(135deg, #40916c 0%, #2d6a4f 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(45, 106, 79, 0.3);
        }

        .btn-stripe-pay {
            background: linear-gradient(135deg, #635bff 0%, #4f46e5 100%);
            color: white;
            border: none;
            font-weight: 600;
            border-radius: 12px;
            padding: 12px 18px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(99, 91, 255, 0.25);
        }

        .btn-stripe-pay:hover {
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            color: white;
            box-shadow: 0 6px 18px rgba(99, 91, 255, 0.4);
            transform: translateY(-2px);
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid rgba(0,0,0,0.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }

        .devsecops-pill {
            font-size: 0.72rem;
            font-weight: 600;
            background: #e9ecef;
            color: #495057;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
    </style>
</head>
<body>

    <!-- Header & Navigation Bar -->
    <header class="bg-white border-bottom sticky-top shadow-sm py-2">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="index.php" class="text-decoration-none d-flex align-items-center gap-2">
                <span class="navbar-brand-badge fs-4">
                    <i class="bi bi-flower1"></i> AgroLocal
                </span>
                <span class="d-none d-md-inline text-muted fs-6 fw-medium">Direct din Fermă</span>
            </a>

            <div class="d-flex align-items-center gap-3">
                <span class="devsecops-pill d-none d-lg-inline-flex">
                    <i class="bi bi-shield-check text-success"></i> DevSecOps Protected (Anti-Stego & CSRF)
                </span>
                <button type="button" class="btn btn-agro btn-sm px-3" data-bs-toggle="modal" data-bs-target="#farmerAddProductModal">
                    <i class="bi bi-plus-circle me-1"></i> Adaugă Produs Bio
                </button>
            </div>
        </div>
    </header>

    <!-- Notificări Status Stripe & Flash Alerts -->
    <div class="container mt-3">
        <?php if ($paymentStatus === 'success'): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 rounded-4 p-4" role="alert">
                <div class="d-flex align-items-center gap-3">
                    <div class="fs-1 text-success"><i class="bi bi-check-circle-fill"></i></div>
                    <div>
                        <h4 class="alert-heading fw-bold mb-1">Plată Stripe Efectuată cu Succes!</h4>
                        <p class="mb-1">Comanda ta pentru <strong><?= $paidProd ?></strong> a fost înregistrată direct în sistemul fermierului local.</p>
                        <small class="text-muted">ID Sesiune Checkout: <code><?= $sessionId ?: 'cs_live_simulated' ?></code></small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($paymentStatus === 'cancel'): ?>
            <div class="alert alert-warning alert-dismissible fade show shadow-sm border-0 rounded-4 p-3" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                Procesul de plată a fost anulat. Poți reîncerca oricând cumpărarea direct de la fermier.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($flashSuccess): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 rounded-4" role="alert">
                <i class="bi bi-check-lg me-2"></i> <?= htmlspecialchars($flashSuccess) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 rounded-4" role="alert">
                <i class="bi bi-shield-x me-2"></i> <?= htmlspecialchars($flashError) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!$mongoConnected): ?>
            <div class="alert alert-info border-0 rounded-4 shadow-sm mb-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle-fill text-primary fs-5"></i>
                    <div>
                        <strong>Mod Demonstrație Activ:</strong> <?= htmlspecialchars($mongoError ?? 'MongoDB server offline') ?>
                        <br><small>Pentru mediul complet, rulează containerul MongoDB parolat conform fișierului <code>mongo-init.js</code>.</small>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Hero Section -->
    <section class="hero-banner py-5 mb-5">
        <div class="container">
            <div class="row align-items-center gy-4">
                <div class="col-lg-7">
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-1 bg-white border rounded-pill shadow-sm mb-3">
                        <span class="badge bg-success rounded-pill">100% BIO</span>
                        <span class="small fw-semibold text-dark">Certificate RO-ECO oficiale</span>
                    </div>
                    <h1 class="serif-heading display-4 fw-bold text-dark mb-3">
                        Gustoase, Curate, Sănătoase.<br>
                        <span class="text-success">Direct din Grădina Fermierilor.</span>
                    </h1>
                    <p class="lead text-secondary mb-4">
                        Platforma e-commerce care elimină intermediarii. Beneficiezi de <strong>5% Eco-Discount</strong> garantat pe fiecare produs bio atestat și susții direct agricultura sustenabilă românească.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="#produse" class="btn btn-agro btn-lg px-4">
                            <i class="bi bi-basket2 me-2"></i> Explorează Produsele
                        </a>
                        <button type="button" class="btn btn-outline-dark btn-lg px-4" data-bs-toggle="modal" data-bs-target="#farmerAddProductModal">
                            <i class="bi bi-shop me-2"></i> Panou Fermier (Înrolare Produs)
                        </button>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <i class="bi bi-tree text-success fs-1 mb-2"></i>
                                <h3 class="fw-bold mb-0">100% Bio</h3>
                                <p class="text-muted small mb-0">Fără pesticide sintetice</p>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <i class="bi bi-patch-check text-primary fs-1 mb-2"></i>
                                <h3 class="fw-bold mb-0">RO-ECO</h3>
                                <p class="text-muted small mb-0">Validare certificare</p>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <i class="bi bi-credit-card-2-front text-indigo fs-1 mb-2" style="color: #635bff;"></i>
                                <h3 class="fw-bold mb-0">Stripe</h3>
                                <p class="text-muted small mb-0">Plăți cu cardul sigure</p>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card text-center">
                                <i class="bi bi-shield-lock text-warning fs-1 mb-2"></i>
                                <h3 class="fw-bold mb-0">GD Clean</h3>
                                <p class="text-muted small mb-0">Anti-Steganografie</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Catalog Produse Section -->
    <main class="container mb-5" id="produse">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-2 border-bottom">
            <div>
                <h2 class="serif-heading fw-bold mb-1">Recoltă Proaspătă Disponibilă Acum</h2>
                <p class="text-muted mb-0">Toate produsele beneficiază automat de reducerea de 5% conform standardelor de calitate.</p>
            </div>
            <div class="mt-3 mt-md-0">
                <span class="badge bg-light text-dark border p-2 px-3 rounded-pill">
                    <i class="bi bi-grid-fill text-success me-1"></i> <?= count($products) ?> Produse Atestate
                </span>
            </div>
        </div>

        <div class="row g-4">
            <?php foreach ($products as $prod): ?>
                <?php
                    $idStr = (string)($prod['_id'] ?? '');
                    $pName = htmlspecialchars($prod['name'] ?? 'Produs');
                    $pSlug = htmlspecialchars($prod['slug'] ?? 'produs');
                    $basePrice = (float)($prod['base_price'] ?? 0);
                    $finalPrice = (float)($prod['final_price'] ?? 0);
                    $stock = (int)($prod['stock'] ?? 0);
                    $ecoCert = htmlspecialchars($prod['eco_certificate'] ?? 'RO-ECO-000');
                    $imageFile = htmlspecialchars($prod['image_filename'] ?? 'default_honey.jpg');
                    $imagePath = 'uploads/' . $imageFile;
                    if (!file_exists(__DIR__ . '/' . $imagePath)) {
                        $imagePath = 'uploads/default_honey.jpg';
                    }
                ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <article class="card product-card h-100 shadow-sm">
                        <div class="product-img-wrapper">
                            <img src="<?= $imagePath ?>" alt="<?= $pName ?>" class="product-img" loading="lazy">
                            <span class="eco-ribbon">
                                <i class="bi bi-award-fill me-1 text-warning"></i> <?= $ecoCert ?>
                            </span>
                            <span class="stock-badge">
                                <?php if ($stock > 0): ?>
                                    <i class="bi bi-box-seam me-1 text-success"></i> <?= $stock ?> buc în stoc
                                <?php else: ?>
                                    <span class="text-danger">Epuizat</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="card-body p-4 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">
                                        Fermier Local Verificat
                                    </span>
                                </div>
                                <h3 class="h5 fw-bold text-dark mb-2"><?= $pName ?></h3>
                                <p class="text-muted small mb-3">
                                    Produs cultivat fără substanțe chimice dăunătoare, recoltat manual și ambalat în condiții optime de prospețime.
                                </p>
                            </div>

                            <div class="pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-baseline mb-3">
                                    <div>
                                        <span class="old-price me-2"><?= number_format($basePrice, 2) ?> RON</span>
                                        <span class="price-tag"><?= number_format($finalPrice, 2) ?> <small class="fs-6 fw-bold">RON</small></span>
                                    </div>
                                    <span class="badge bg-danger-subtle text-danger rounded-pill fw-semibold">
                                        -5% ECO
                                    </span>
                                </div>

                                <!-- Formular Cumpără prin Stripe -->
                                <form action="index.php" method="POST">
                                    <input type="hidden" name="action" value="buy_product">
                                    <input type="hidden" name="product_id" value="<?= $idStr ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    
                                    <button type="submit" class="btn btn-stripe-pay w-100 d-flex justify-content-center align-items-center gap-2" <?= ($stock <= 0) ? 'disabled' : '' ?>>
                                        <i class="bi bi-stripe fs-5"></i>
                                        <span>Cumpără de la fermier (Stripe)</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- Modal Fermier: Adăugare Produs Bio Securizat -->
    <div class="modal fade" id="farmerAddProductModal" tabindex="-1" aria-labelledby="farmerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 rounded-4 shadow">
                <div class="modal-header bg-light border-0 p-4 pb-2">
                    <div>
                        <h4 class="modal-title fw-bold text-dark" id="farmerModalLabel">
                            <i class="bi bi-shield-plus text-success me-2"></i>Înrolare Produs Bio de către Fermier
                        </h4>
                        <p class="text-muted small mb-0">Protecție completă DevSecOps: Curățare EXIF (Anti-Steganografie), Validare OOP & CSRF</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>

                <form action="index.php" method="POST" enctype="multipart/form-data" class="p-4 pt-2">
                    <input type="hidden" name="action" value="add_product">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                    <div class="modal-body p-0">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="prod_name" class="form-label fw-semibold">Denumire Produs Agricol</label>
                                <input type="text" class="form-control form-control-lg rounded-3" id="prod_name" name="name" placeholder="Ex: Mere Bio Ionatan de Voinești" minlength="3" maxlength="100" required>
                                <div class="form-text">Între 3 și 100 caractere conform regulilor clasei AbstractProduct.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="prod_base_price" class="form-label fw-semibold">Preț de Bază (RON)</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0.01" class="form-control form-control-lg rounded-start-3" id="prod_base_price" name="base_price" placeholder="45.00" required>
                                    <span class="input-group-text rounded-end-3">RON</span>
                                </div>
                                <div class="form-text">Prețul final va fi calculat automat cu -5% discount eco.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="prod_stock" class="form-label fw-semibold">Cantitate în Stoc</label>
                                <input type="number" min="0" step="1" class="form-control form-control-lg rounded-3" id="prod_stock" name="stock" placeholder="50" required>
                                <div class="form-text">Număr de bucăți / kilograme disponibile.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="prod_eco" class="form-label fw-semibold">Certificat Ecologic Oficial (RO-ECO-xxx)</label>
                                <input type="text" class="form-control form-control-lg rounded-3" id="prod_eco" name="eco_certificate" placeholder="RO-ECO-451" pattern="^RO-ECO-\d{3,6}$" required>
                                <div class="form-text">Format strict impus prin BioProduct: <code>RO-ECO-123</code>.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="prod_image" class="form-label fw-semibold">Fotografie Reală din Fermă</label>
                                <input type="file" class="form-control form-control-lg rounded-3" id="prod_image" name="image" accept="image/jpeg,image/png" required>
                                <div class="form-text">Format JPG sau PNG, max 2MB (reconstruită în memorie prin GD).</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-0 p-0 pt-4 mt-3">
                        <button type="button" class="btn btn-light rounded-3 px-4" data-bs-dismiss="modal">Anulează</button>
                        <button type="submit" class="btn btn-agro btn-lg rounded-3 px-4">
                            <i class="bi bi-cloud-arrow-up me-2"></i> Validează & Publică Produsul
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer DevSecOps -->
    <footer class="bg-dark text-white py-5 mt-auto border-top border-secondary">
        <div class="container">
            <div class="row gy-4">
                <div class="col-lg-4">
                    <h5 class="serif-heading text-success fw-bold fs-4 mb-3">AgroLocal</h5>
                    <p class="text-white-50 small">
                        Piață digitală dedicată producătorilor agricoli locali din România. Arhitectură orientată pe obiecte în PHP 8.2, persistență NoSQL MongoDB și plăți sigure prin Stripe.
                    </p>
                </div>
                <div class="col-lg-4">
                    <h6 class="text-uppercase fw-bold text-light mb-3">Măsuri de Securitate DevSecOps</h6>
                    <ul class="list-unstyled text-white-50 small mb-0">
                        <li class="mb-2"><i class="bi bi-shield-check text-success me-2"></i> Autentificare obligatorie MongoDB cu utilizator parolat</li>
                        <li class="mb-2"><i class="bi bi-shield-check text-success me-2"></i> Sanitizare imagini Anti-Steganografie (GD re-render)</li>
                        <li class="mb-2"><i class="bi bi-shield-check text-success me-2"></i> Validare token CSRF (hash_equals criptografic)</li>
                        <li class="mb-2"><i class="bi bi-shield-check text-success me-2"></i> Headers anti-clickjacking & MIME nosniff prin .htaccess</li>
                    </ul>
                </div>
                <div class="col-lg-4">
                    <h6 class="text-uppercase fw-bold text-light mb-3">Conformitate & Testare</h6>
                    <p class="text-white-50 small mb-2">
                        Suită de teste automate PHPUnit (Unit & Integration) integrată în GitHub Actions CI/CD Pipeline.
                    </p>
                    <div class="d-flex gap-2">
                        <span class="badge bg-secondary">PHP 8.2</span>
                        <span class="badge bg-secondary">MongoDB 7.0</span>
                        <span class="badge bg-secondary">Stripe SCA</span>
                        <span class="badge bg-success">100% Bio</span>
                    </div>
                </div>
            </div>
            <div class="border-top border-secondary mt-4 pt-4 text-center text-white-50 small">
                &copy; <?= date('Y') ?> AgroLocal Marketplace. Toate drepturile rezervate. Dezvoltat cu standarde avansate de Securitate și Performanță.
            </div>
        </div>
    </footer>

    <!-- Bootstrap 5.3 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
