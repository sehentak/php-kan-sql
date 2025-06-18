<?php

namespace Sehentak\PhpKanSql\Commands;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class GenerateCrudCommand
 * Perintah utama untuk membaca skema SQL dan menghasilkan file Model & Controller
 * yang fungsional menggunakan PDO murni.
 */
class GenerateCrudCommand extends Command
{
    private string $projectRoot;
    private InputInterface $input; // Properti untuk menyimpan input

    public function __construct(?string $name = null)
    {
        parent::__construct($name);
        $this->projectRoot = getcwd();
    }

    /**
     * Konfigurasi perintah CLI, termasuk nama, deskripsi, dan argumen.
     */
    protected function configure(): void
    {
        $this
            ->setName('make:crud')
            ->setDescription('Membuat Model & Controller PDO fungsional dari skema SQL.')
            ->addArgument('sql_file', InputArgument::REQUIRED, 'Path ke file skema .sql.')
            ->addOption(
                'setup',
                null,
                InputOption::VALUE_OPTIONAL,
                'Generate E2E setup. Pilihan: <fg=yellow>apache</>, <fg=yellow>nginx</>. Jika kosong, default ke apache.',
                false
            );
    }

    /**
     * Logika utama yang akan dieksekusi saat perintah dijalankan.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input; // Simpan input agar bisa diakses di metode lain jika perlu

        try {
            $setupMode = $input->getOption('setup');
            // Jika opsi --setup digunakan tanpa nilai, set default ke 'apache'
            if ($setupMode === null && $input->hasParameterOption('--setup')) {
                $setupMode = 'apache';
            }

            $sqlFilePath = $this->projectRoot . '/' . $input->getArgument('sql_file');
            
            $output->writeln("<info>Membaca file skema:</info> {$sqlFilePath}");
            if (!file_exists($sqlFilePath)) throw new Exception("File tidak ditemukan.");

            $sqlContent = file_get_contents($sqlFilePath);

            $tableName = $this->parseTableName($sqlContent);
            $columns = $this->parseColumns($sqlContent);
            $output->writeln("<comment>Tabel terdeteksi:</comment> {$tableName}");

            $hasSoftDeletes = in_array('deleted_at', $columns);
            
            $output->writeln("<comment>Tabel terdeteksi:</comment> {$tableName}");
            if ($hasSoftDeletes) $output->writeln("<comment>Fitur Soft Delete akan diaktifkan.</comment>");

            $modelName = $this->studlyCase($this->singular($tableName));
            
            // Generate file-file inti
            $this->generateModel($output, $modelName, $columns);
            // --- PERUBAHAN DI SINI: Melewatkan $columns yang sudah diparsing ---
            $this->generateController($output, $modelName, $tableName, $columns, $hasSoftDeletes);
            
            // Jika mode setup aktif, generate file pendukung
            if ($setupMode !== false) {
                $output->writeln("\n<info>Mode --setup aktif. Menghasilkan file pendukung...</info>");
                $this->generateDatabaseBootstrap($output);
                $this->generateRouter($output);
                $this->generateEnvExample($output);

                switch ($setupMode) {
                    case 'apache':
                        $this->generateHtaccess($output);
                        break;
                    case 'nginx':
                        $this->generateNginxConfig($output);
                        break;
                    default:
                        $output->writeln("<warning>Pilihan setup '{$setupMode}' tidak valid.</warning>");
                        break;
                }
                $this->installDotenvDependency($output);
                $this->printSetupInstructions($output);
            }

            $output->writeln("\n<question>✅ Sukses! Kode fungsional telah berhasil dibuat.</question>");
            return Command::SUCCESS;

        } catch (Exception $e) {
            $output->writeln("\n<error>Sebuah kesalahan terjadi:</error> " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Menghasilkan file Model berdasarkan template.
     */
    private function generateModel(OutputInterface $output, string $modelName, array $columns): void
    {
        $properties = '';
        foreach ($columns as $column) {
            $properties .= "    public \$" . $this->camelCase($column) . ";\n";
        }

        $this->createFromStub(
            $output,
            $this->projectRoot . '/src/Models/' . $modelName . '.php',
            'model.stub',
            [
                '{{ modelName }}' => $modelName,
                '{{ properties }}' => rtrim($properties),
            ],
            "Model `{$modelName}`"
        );
    }

    /**
     * Menghasilkan file Controller berdasarkan template.
     */
    private function generateController(OutputInterface $output, string $modelName, string $tableName, array $columns, bool $hasSoftDeletes): void
    {
        $controllerName = "{$modelName}Controller";
        
        // Menggunakan $columns yang sudah dilewatkan, bukan mem-parsing ulang
        $fillableColumns = array_diff($columns, ['id', 'created_at', 'updated_at', 'deleted_at']);
        
        $insertColumns = '`' . implode('`, `', $fillableColumns) . '`';
        $insertPlaceholders = rtrim(str_repeat('?, ', count($fillableColumns)), ', ');
        
        $updateSet = '';
        foreach ($fillableColumns as $column) {
            $updateSet .= "`{$column}` = ?, ";
        }
        $updateSet = rtrim($updateSet, ', ');

        $extraMethods = '';
        if ($hasSoftDeletes) {
            $restoreStub = file_get_contents(__DIR__ . '/../../templates/stubs/restore_method.stub');
            // Menambahkan '{{ tableName }}' ke dalam array pengganti
            $extraMethods = str_replace(
                ['{{ modelName }}', '{{ modelVariable }}', '{{ tableName }}'],
                [$modelName, lcfirst($modelName), $tableName],
                $restoreStub
            );
        }

        $this->createFromStub(
            $output,
            $this->projectRoot . '/src/Http/Controllers/' . $controllerName . '.php',
            'controller.stub',
            [
                '{{ namespace }}' => 'App\Http\Controllers',
                '{{ modelNamespace }}' => 'App\Models\\' . $modelName,
                '{{ controllerName }}' => $controllerName,
                '{{ modelName }}' => $modelName,
                '{{ tableName }}' => $tableName,
                '{{ softDeleteWhereClause }}' => $hasSoftDeletes ? "WHERE `deleted_at` IS NULL" : "",
                '{{ softDeleteAndWhereClause }}' => $hasSoftDeletes ? "AND `deleted_at` IS NULL" : "",
                '{{ insertColumns }}' => $insertColumns,
                '{{ insertPlaceholders }}' => $insertPlaceholders,
                '{{ updateSetClause }}' => $updateSet,
                '{{ deleteStatement }}' => $hasSoftDeletes ? "UPDATE `{$tableName}` SET `deleted_at` = NOW() WHERE `id` = ?" : "DELETE FROM `{$tableName}` WHERE `id` = ?",
                '{{ extraApiMethods }}' => $extraMethods,
            ],
            "Controller `{$controllerName}`"
        );
    }

    private function generateDatabaseBootstrap(OutputInterface $output): void
    {
        $this->createFromStub($output, $this->projectRoot . '/bootstrap/database.php', 'setup/database.php.stub', [], "Bootstrap Database");
    }

    private function generateRouter(OutputInterface $output): void
    {
        $this->createFromStub($output, $this->projectRoot . '/public/index.php', 'setup/router.php.stub', [], "Router (index.php)");
    }

    private function generateHtaccess(OutputInterface $output): void
    {
        $this->createFromStub($output, $this->projectRoot . '/public/.htaccess', 'setup/htaccess.stub', [], "Konfigurasi Apache (.htaccess)");
    }

    private function generateNginxConfig(OutputInterface $output): void
    {
        $this->createFromStub($output, $this->projectRoot . '/nginx.conf.example', 'setup/nginx.conf.stub', [], "Contoh Konfigurasi Nginx");
    }

    private function generateEnvExample(OutputInterface $output): void
    {
        $this->createFromStub($output, $this->projectRoot . '/.env.example', 'setup/env.example.stub', [], "Contoh Environment (.env.example)");
    }

    /**
     * Menginstal dependensi vlucas/phpdotenv secara otomatis.
     */
    private function installDotenvDependency(OutputInterface $output): void
    {
        $composerJsonPath = $this->projectRoot . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            $output->writeln("<warning>composer.json tidak ditemukan. Melewati instalasi otomatis vlucas/phpdotenv.</warning>");
            return;
        }

        $composerConfig = json_decode(file_get_contents($composerJsonPath), true);
        $isInstalled = isset($composerConfig['require']['vlucas/phpdotenv']);

        if ($isInstalled) {
            $output->writeln("<info>Dependensi vlucas/phpdotenv sudah terinstal.</info>");
            return;
        }

        $output->writeln("<info>Mencoba menginstal vlucas/phpdotenv...</info>");
        
        // Menjalankan perintah composer dari direktori proyek pengguna
        $command = 'composer require vlucas/phpdotenv --working-dir=' . escapeshellarg($this->projectRoot);
        shell_exec($command);

        clearstatcache(); // Membersihkan cache status file
        $composerConfig = json_decode(file_get_contents($composerJsonPath), true);
        if (isset($composerConfig['require']['vlucas/phpdotenv'])) {
            $output->writeln("<info>✅ vlucas/phpdotenv berhasil diinstal.</info>");
        } else {
            $output->writeln("<error>Gagal menginstal vlucas/phpdotenv secara otomatis. Silakan jalankan manual: composer require vlucas/phpdotenv</error>");
        }
    }
    
    // --- METODE YANG DIPERBARUI DI SINI ---
    private function printSetupInstructions(OutputInterface $output): void
    {
        $output->writeln("\n<bg=blue;fg=white> LANGKAH SELANJUTNYA (SETUP) </>");
        $output->writeln("1. Salin `.env.example` menjadi `.env` dan isi kredensial database Anda.");
        $output->writeln("   <fg=yellow>cp .env.example .env</>");
        $output->writeln("2. Dependensi <fg=cyan>vlucas/phpdotenv</> telah diinstal secara otomatis untuk Anda.");
        $output->writeln("3. Konfigurasikan web server Anda (Apache/Nginx) agar menunjuk ke direktori <fg=yellow>public</>.");
        $output->writeln("4. Untuk development, jalankan server PHP bawaan:");
        $output->writeln("   <fg=yellow>php -S localhost:8000 -t public</>");
    }
    
    /**
     * Fungsi inti untuk membuat file dari sebuah template (stub).
     */
    private function createFromStub(OutputInterface $output, string $filePath, string $stubName, array $replacements, string $entityName): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        if (file_exists($filePath)) {
            $output->writeln("<comment>{$entityName} sudah ada, pembuatan dilewati.</comment>");
            return;
        }
        $stubPath = __DIR__ . '/../../templates/' . $stubName;
        if (!file_exists($stubPath)) throw new Exception("FATAL: Template `{$stubName}` tidak ditemukan.");
        $stubContent = file_get_contents($stubPath);
        $generatedContent = str_replace(array_keys($replacements), array_values($replacements), $stubContent);
        file_put_contents($filePath, $generatedContent);
        $output->writeln("<info>✅ {$entityName} berhasil dibuat di:</info> " . str_replace($this->projectRoot . '/', '', $filePath));
    }

    // --- Helper Methods ---
    private function studlyCase(string $value): string { return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value))); }
    private function camelCase(string $value): string { return lcfirst($this->studlyCase($value)); }
    private function singular(string $value): string { return rtrim($value, 's'); }
    private function plural(string $value): string { return $value . 's'; }
    private function parseTableName(string $sql): string { if (preg_match('/CREATE TABLE `?(\w+)`?/', $sql, $matches)) return $matches[1]; throw new Exception("Tidak dapat mem-parsing nama tabel."); }
    private function parseColumns(string $sql): array
    {
        if (!preg_match('/\((.*)\)/s', $sql, $matches)) throw new Exception("Struktur kolom tidak valid.");
        $columns = []; $lines = preg_split('/,\s*\n/', $matches[1]); $excludedKeywords = ['PRIMARY KEY', 'CONSTRAINT', 'UNIQUE KEY', 'KEY'];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^`?(\w+)`?/', $line, $colMatches)) {
                $isKeywordLine = false;
                foreach ($excludedKeywords as $keyword) if (str_starts_with(strtoupper($line), $keyword)) { $isKeywordLine = true; break; }
                if (!$isKeywordLine) $columns[] = $colMatches[1];
            }
        }
        if (empty($columns)) throw new Exception("Tidak ada kolom yang berhasil diparsing.");
        return $columns;
    }
}