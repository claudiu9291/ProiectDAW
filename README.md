# 🌾 AgroLocal - Platformă E-Commerce pentru Fermieri Locali

**AgroLocal** este o soluție e-commerce orientată pe obiecte (PHP 8.2 OOP), cu persistență pe bază de documente în **MongoDB** și procesare securizată a plăților prin **Stripe Checkout**. Platforma permite clienților să achiziționeze produse proaspete și bio direct de la fermierii locali români, eliminând intermediarii și oferind un discount ecologic de 5% pe produsele certificate.

---

## 🛡️ Arhitectură & Măsuri DevSecOps

1. **Parolă obligatorie la MongoDB (`Database.php`)**:
   - Conexiunea la baza de date refuză pornirea (`RuntimeException`) dacă variabila `MONGO_PASS` lipsește sau este vidă.
2. **Igienizare Imagini & Anti-Steganografie (`ImageSanitizer.php`)**:
   - Validare tip MIME binar prin `finfo` (doar `image/jpeg` și `image/png`).
   - Limită strictă de dimensiune: 2 MB.
   - **Reconstrucție pixel-cu-pixel în memorie prin GD**: Distruge metadatele EXIF și orice payload ascuns înainte de salvarea pe disc cu un nume pseudo-aleatoriu (`bin2hex(random_bytes(16))`).
3. **Protecție Anti-CSRF (`CsrfGuard.php`)**:
   - Generare token criptografic (`random_bytes(32)`) stocat în sesiune.
   - Validare sincronă cu protecție împotriva atacurilor de tip timing (`hash_equals`).
4. **Hardening Apache & Directoare (`.htaccess`)**:
   - Dezactivare listare directoare (`Options -Indexes`).
   - Blocare fișiere sensibile (`.env`, `.git`, `.json`, `.lock`, etc.).
   - Dezactivare execuție motoare PHP în folderul `public/uploads/` (`php_flag engine off`).
   - Headers de securitate: `X-Content-Type-Options: nosniff` și `X-Frame-Options: DENY`.
5. **Validare JSON Schema la nivelul colecției MongoDB (`mongo-init.js`)**:
   - Impune câmpuri obligatorii (`name`, `slug`, `base_price`, `final_price`, `stock`, `image_filename`) și constrângeri de tip și valoare minimă.

---

## 📂 Structura Fișierelor

```
ProiectDAW/
├── .github/
│   └── workflows/
│       └── ci-cd.yml             # Pipeline GitHub Actions (Testare Unit + Integrare + Deploy)
├── public/
│   ├── uploads/
│   │   ├── .htaccess             # Blocare scripturi executabile în uploads
│   │   ├── default_honey.jpg     # Imagine implicită miere bio
│   │   └── default_tomatoes.jpg  # Imagine implicită roșii inima de bou
│   ├── .htaccess                 # Rewrite slug produse, blocare fișiere ascunse, headers
│   ├── index.php                 # Aplicație web completă (Bootstrap 5, SEO JSON-LD, Stripe, Formular Bio)
│   └── robots.txt                # Directiva de indexare SEO
├── src/
│   ├── Config/
│   │   └── Database.php          # Conexiune securizată MongoDB cu parolă obligatorie
│   ├── Contracts/
│   │   └── PaymentGatewayInterface.php # Interfață procesare plăți
│   ├── Models/
│   │   ├── AbstractProduct.php   # Clasă abstractă cu validare nume, preț, stoc
│   │   └── BioProduct.php        # Extensie cu discount 5% și validare regex RO-ECO
│   ├── Security/
│   │   ├── CsrfGuard.php         # Generare și verificare token anti-CSRF
│   │   └── ImageSanitizer.php    # Recreare GD anti-steganografie
│   └── Services/
│       └── StripePaymentService.php # Serviciu Stripe Checkout Session
├── tests/
│   ├── Integration/
│   │   └── CheckoutIntegrationTest.php # Test creare comenzi MongoDB + Stripe Mock
│   └── Unit/
│       └── BioProductTest.php    # Test unitar discount eco și format certificat
├── .env.example                  # Variabile de mediu demonstrative
├── composer.json                 # Dependențe PHP 8.2, MongoDB, Stripe & PHPUnit
├── Dockerfile                    # Container PHP 8.2 Apache cu extensii gd & mongodb
├── docker-compose.yml            # Stack complet: Aplicație + MongoDB 7.0 parolat
├── mongo-init.js                 # Inițializare colecții, JSON schema validator & seed data
└── phpunit.xml                   # Configurare suite teste PHPUnit
```

---

## 🚀 Rulare Rapidă cu Docker (Recomandat)

Asigură-te că Docker Desktop este pornit și rulează:

```bash
docker compose up -d
```

Aplicația va fi accesibilă la adresa: **[http://localhost:8000](http://localhost:8000)**.
MongoDB va fi pornit pe portul `27017` cu utilizatorul parolat `agro_user` / `AgroPass!2026#Sec`.

Pentru a rula suita de teste automate:
```bash
docker compose exec app vendor/bin/phpunit
```

---

## 🧪 Rulare Teste PHPUnit

Dacă ai PHP 8.2 și extensia MongoDB instalate local:

```bash
composer install
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Integration
```

---

## 🔍 Optimizare SEO
- **Titlu & Meta Descriere unice** pentru căutări legate de produse bio și fermieri locali.
- **Microdate Schema.org JSON-LD** de tip `ItemList` & `Product` cu oferte de preț și stoc.
- **Carduri OpenGraph & Twitter** integrate pentru partajare optimă în social media.
- **Robots.txt** optimizat pentru crawlerii motoarelor de căutare.
