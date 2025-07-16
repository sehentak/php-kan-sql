PHP-Kan-SQL

PHP-Kan-SQL adalah sebuah library baris perintah (CLI) yang sangat ringan untuk developer PHP yang ingin mempercepat pembuatan RESTful API. Cukup dengan menyediakan file skema .sql, library ini akan secara otomatis menghasilkan Controller dan Model fungsional menggunakan PDO murni, tanpa ketergantungan pada framework apa pun.

Terinspirasi dari sqlc untuk Go, alat ini bertujuan untuk membawa kemudahan yang sama ke dalam ekosistem PHP.
✨ Fitur Utama

    Generator Kode Fungsional: Menghasilkan kode Controller dengan logika CRUD (Create, Read, Update, Delete) yang lengkap dan siap pakai.

    PDO Murni: Tidak ada ketergantungan pada framework. Kode yang dihasilkan dapat berjalan di proyek PHP mana pun, baik itu vanilla PHP, Slim, atau framework lainnya.

    Deteksi Soft Delete: Secara otomatis mendeteksi kolom deleted_at dan menghasilkan logika soft delete serta metode restore() yang sesuai.

    Mode Setup End-to-End: Opsi --setup dapat secara instan membuat struktur proyek API sederhana yang bisa langsung dijalankan, lengkap dengan router dan konfigurasi server contoh (Apache/Nginx).

    Instalasi Dependensi Otomatis: Dalam mode --setup, dependensi yang diperlukan seperti vlucas/phpdotenv akan diinstal secara otomatis.

🚀 Instalasi

Anda dapat menambahkan PHP-Kan-SQL sebagai dependensi ke dalam proyek Anda menggunakan Composer.

`composer require sehentak/php-kan-sql`

(Catatan: Saat ini, library ini belum dipublikasikan ke Packagist. Untuk testing lokal, silakan lihat panduan Wiki).
💡 Cara Penggunaan

Setelah terinstal, Anda bisa memanggil perintah make:crud dari direktori root proyek Anda melalui vendor/bin/generate.
Mode Standar (Hanya Generator Kode)

Mode ini hanya akan menghasilkan file Model dan Controller di dalam struktur proyek Anda yang sudah ada.

Perintah:

`vendor/bin/generate make:crud path/ke/file_skema.sql`

Contoh:

`vendor/bin/generate make:crud database/schema/users.sql`

Hasil:

    src/Models/User.php

    src/Http/Controllers/UserController.php

Mode End-to-End (--setup)

Mode ini akan membuat API sederhana yang bisa langsung dijalankan.

Perintah:

# Untuk Apache (default)
`vendor/bin/generate make:crud path/ke/file_skema.sql --setup`

# Atau secara eksplisit
`vendor/bin/generate make:crud path/ke/file_skema.sql --setup=apache`

# Untuk Nginx
`vendor/bin/generate make:crud path/ke/file_skema.sql --setup=nginx`

Hasil Tambahan (Selain Model & Controller):

    bootstrap/database.php

    public/index.php

    .env.example

    public/.htaccess (jika menggunakan --setup=apache)

    nginx.conf.example (jika menggunakan --setup=nginx)

    Dependensi vlucas/phpdotenv akan otomatis ditambahkan ke composer.json Anda.

🤝 Berkontribusi

Kontribusi dalam bentuk apa pun sangat kami hargai! Silakan lihat CONTRIBUTING.md untuk panduan lebih lanjut tentang cara berkontribusi pada proyek ini.
📜 Lisensi

Proyek ini dilisensikan di bawah Lisensi MIT. Lihat file LICENSE untuk detail lebih lanjut.
