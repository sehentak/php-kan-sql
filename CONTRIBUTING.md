Panduan Berkontribusi

Kami sangat senang Anda tertarik untuk berkontribusi pada PHP-Kan-SQL! Setiap kontribusi, sekecil apa pun, sangat kami hargai dan akan kami berikan kredit penuh.

Untuk memastikan proses yang lancar bagi semua orang, silakan baca panduan berikut.
Cara Berkontribusi

Cara yang kami rekomendasikan untuk berkontribusi adalah melalui Pull Request.

    Fork Repositori: Klik tombol "Fork" di pojok kanan atas halaman repositori utama. Ini akan membuat salinan repositori di bawah akun GitHub Anda.

    Clone Fork Anda: Clone repositori hasil fork Anda ke mesin lokal agar Anda bisa mulai bekerja.

    git clone https://github.com/NAMA_ANDA/php-kan-sql.git
    cd php-kan-sql

    Buat Branch Baru: Sangat penting untuk membuat branch baru untuk setiap fitur atau perbaikan bug yang Anda kerjakan. Ini menjaga riwayat commit tetap bersih. Gunakan nama yang deskriptif.

    # Untuk fitur baru:
    git checkout -b feature/tambahkan-dukungan-postgresql

    # Untuk perbaikan bug:
    git checkout -b fix/masalah-parsing-kolom

    Lakukan Perubahan: Lakukan perubahan atau penambahan kode Anda. Ikuti standar kode yang ada dan pastikan untuk menambahkan komentar jika Anda membuat logika yang kompleks.

    Commit Perubahan Anda: Buat commit dengan pesan yang jelas dan deskriptif menggunakan Conventional Commits.

        feat: untuk fitur baru.

        fix: untuk perbaikan bug.

        docs: untuk perubahan dokumentasi.

        style: untuk format kode.

        refactor: untuk refactoring kode.

        test: untuk menambahkan tes.

    git commit -m "feat: Menambahkan opsi --setup untuk Nginx"

    Push ke Branch Anda: Push perubahan Anda ke repositori fork Anda di GitHub.

    git push origin feature/tambahkan-dukungan-postgresql

    Buat Pull Request (PR): Buka halaman repositori fork Anda di GitHub dan Anda akan melihat tombol untuk membuat "Pull Request" baru. Klik tombol tersebut, berikan judul yang jelas, dan isi deskripsi PR dengan detail tentang apa yang Anda ubah dan mengapa. Jika PR Anda terkait dengan sebuah issue, jangan lupa untuk menautkannya (misalnya, "Closes #123").

Laporan Bug atau Permintaan Fitur

Jika Anda menemukan bug atau memiliki ide untuk fitur baru, cara terbaik untuk menyampaikannya adalah dengan membuat Issue baru di tab "Issues" di repositori utama. Mohon periksa terlebih dahulu apakah sudah ada issue yang sama sebelum membuat yang baru.

Terima kasih telah menjadi bagian dari proyek ini!