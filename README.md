# Backend Pilkades 2026

Backend PHP native dan MySQL untuk digunakan di XAMPP. Folder ini diasumsikan dapat dibuka melalui `http://localhost/pilkades/`.

## Persiapan

1. Jalankan Apache dan MySQL dari XAMPP.
2. Untuk database yang sudah dipakai, jalankan migrasi yang belum diterapkan secara berurutan hingga `migration_006_candidate_photos.sql`. Database baru cukup mengimpor `database.sql`.
3. Periksa `config.php`. Nilai awal memakai host `127.0.0.1`, user `root`, dan password kosong, sesuai konfigurasi XAMPP umum. Ubah `DB_PASS` bila MySQL Anda memakai password.
4. Pastikan PHP dapat menulis ke `uploads/`. Foto disimpan di `C:\xampp\htdocs\pilkades\uploads`, diabaikan oleh Git, dan akses langsung diblokir oleh `.htaccess`. Batas ukuran 10 MB (JPG, PNG, WebP).
5. Buka `http://localhost/pilkades/setup.php` dan buat akun admin pertama. Kata sandi minimal 4 karakter.
6. Masuk melalui `http://localhost/pilkades/`.
7. Admin menambahkan calon dan TPS, lalu membuat akun relawan. Relawan memilih TPS saat mengisi hasil.

## Peran dan batasan

- Hanya admin yang dapat mengelola calon, TPS, relawan, dan laporan hasil. Admin dapat menambah dan mengedit calon/TPS/relawan; data yang tidak dirujuk dapat dihapus.
- Admin dapat mengunggah foto calon; foto tampil di daftar calon dan form input suara relawan.
- Calon tidak dapat dihapus setelah ada laporan yang merujuknya; TPS dengan laporan dan relawan dengan riwayat hanya dapat dipertahankan atau dinonaktifkan sesuai relasinya.
- Laporan hasil memiliki aksi Edit/Koreksi dan Hapus permanen khusus admin. Penghapusan laporan turut menghapus suara, riwayat terkait, dan foto bukti.
- Admin dan relawan dapat mengganti kata sandi sendiri dari tautan navigasi dengan memverifikasi kata sandi saat ini.
- Admin dapat menghapus TPS hanya setelah laporan terkait dihapus.
- Relawan dapat mengirim dan mengedit laporan miliknya sendiri. Penghapusan hasil dan koreksi laporan relawan lain hanya tersedia bagi admin.
- Admin dapat membuka laporan, membandingkan foto bukti, lalu mengoreksi suara dengan alasan wajib. Riwayat menyimpan nilai sebelum/sesudah, foto, waktu, dan akun pelaku koreksi.
- Relawan memilih TPS dari daftar dan mengirim jumlah suara semua calon sebagai satu laporan. Foto bukti wajib disertakan pada laporan pertama, maksimal 10 MB dengan format JPG, PNG, atau WebP.
- Grafik perolehan suara ditampilkan sebagai pie chart. Admin melihat total seluruh TPS; relawan melihat rekap TPS setelah input dikirim.
- Input awal dan perubahan hasil beserta pelaku, snapshot sebelum/sesudah, dan referensi foto dicatat pada `hasil_riwayat`. Akun yang dinonaktifkan tetap disimpan agar catatan hasil lama tidak kehilangan referensi.
- Kata sandi disimpan menggunakan `password_hash`; formulir POST dilindungi token CSRF dan akses dibatasi berdasarkan peran.

## Catatan penggunaan

Ini MVP untuk pencatatan internal, bukan sistem resmi/tabulasi KPU. Belum ada pengelolaan sesi deployment publik, reset kata sandi, export, atau verifikasi berlapis. Gunakan HTTPS bila diakses melalui jaringan dan jangan memakai akun `root` untuk deployment produksi.
