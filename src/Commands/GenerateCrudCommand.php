<?php

namespace Sehentak\PhpKanSql\Commands;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class GenerateCrudCommand
 * Perintah utama untuk membaca skema SQL dan menghasilkan file Model & Controller yang fungsional.
 */
class GenerateCrudCommand extends Command
{
    private string $projectRoot;

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
            ->setDescription('Membuat file Model & Controller fungsional dari skema SQL dengan deteksi Soft Delete.')
            ->addArgument('sql_file', InputArgument::REQUIRED, 'Path ke file skema .sql dari root proyek.');
    }

    /**
     * Logika utama yang akan dieksekusi saat perintah dijalankan.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $sqlFilePath = $this->projectRoot . '/' . $input->getArgument('sql_file');
            $output->writeln("<info>Membaca file skema:</info> {$sqlFilePath}");

            if (!file_exists($sqlFilePath)) {
                throw new Exception("File tidak ditemukan di path: {$sqlFilePath}");
            }
            $sqlContent = file_get_contents($sqlFilePath);

            // Langkah 1: Parsing informasi dari SQL
            $tableName = $this->parseTableName($sqlContent);
            $columns = $this->parseColumns($sqlContent);
            $output->writeln("<comment>Nama tabel terdeteksi:</comment> {$tableName}");

            // Langkah 2: Menentukan fitur-fitur khusus (seperti Soft Delete)
            $hasSoftDeletes = in_array('deleted_at', $columns);
            if ($hasSoftDeletes) {
                $output->writeln("<comment>Kolom 'deleted_at' terdeteksi. Fitur Soft Delete akan diaktifkan.</comment>");
            }

            // Langkah 3: Menyiapkan data untuk generator
            $modelName = $this->studlyCase($this->singular($tableName));
            $fillableColumns = array_diff($columns, ['id', 'created_at', 'updated_at', 'deleted_at']);
            
            // --- INI BAGIAN YANG DIPERBAIKI ---
            // Memastikan fungsi generator benar-benar dipanggil.
            $this->generateModel($output, $modelName, $tableName, $fillableColumns, $hasSoftDeletes);
            $this->generateController($output, $modelName, $tableName, $hasSoftDeletes);
            // --- AKHIR PERBAIKAN ---
            
            $output->writeln("\n<question>✅ Sukses! File Model dan Controller fungsional telah berhasil dibuat.</question>");
            return Command::SUCCESS;

        } catch (Exception $e) {
            $output->writeln("\n<error>Sebuah kesalahan terjadi:</error>");
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Menghasilkan file Model berdasarkan template.
     */
    private function generateModel(OutputInterface $output, string $modelName, string $tableName, array $fillableColumns, bool $hasSoftDeletes): void
    {
        $replacements = [
            '{{ modelName }}' => $modelName,
            '{{ tableName }}' => $tableName,
            '{{ fillableColumns }}' => "'" . implode("',\n        '", $fillableColumns) . "'",
            '{{ softDeletesUseStatement }}' => $hasSoftDeletes ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n" : '',
            '{{ useSoftDeletesTrait }}' => $hasSoftDeletes ? "    use SoftDeletes;\n" : '',
        ];

        $this->createFromStub(
            $output,
            $this->projectRoot . '/src/Models/' . $modelName . '.php',
            'model.stub',
            $replacements,
            "Model `{$modelName}`"
        );
    }

    /**
     * Menghasilkan file Controller berdasarkan template.
     */
    private function generateController(OutputInterface $output, string $modelName, string $tableName, bool $hasSoftDeletes): void
    {
        $controllerName = "{$modelName}Controller";
        
        $extraMethods = '';
        if ($hasSoftDeletes) {
            $restoreStub = file_get_contents(__DIR__ . '/../../templates/stubs/restore_method.stub');
            $extraMethods = str_replace(
                ['{{ modelName }}', '{{ modelVariable }}'],
                [$modelName, lcfirst($modelName)],
                $restoreStub
            );
        }

        $replacements = [
            '{{ namespace }}' => 'App\Http\Controllers',
            '{{ modelNamespace }}' => 'App\Models\\' . $modelName,
            '{{ controllerName }}' => $controllerName,
            '{{ modelName }}' => $modelName,
            '{{ modelVariable }}' => lcfirst($modelName),
            '{{ modelVariablePlural }}' => $this->plural(lcfirst($modelName)),
            '{{ destroyComment }}' => $hasSoftDeletes ? 'Memindahkan resource ke tong sampah dengan mengisi `deleted_at` (soft delete).' : 'Menghapus resource secara permanen dari database.',
            '{{ extraApiMethods }}' => $extraMethods,
        ];

        $this->createFromStub(
            $output,
            $this->projectRoot . '/src/Http/Controllers/' . $controllerName . '.php',
            'controller.stub',
            $replacements,
            "Controller `{$controllerName}`"
        );
    }
    
    /**
     * Fungsi inti untuk membuat file dari sebuah template (stub).
     */
    private function createFromStub(OutputInterface $output, string $filePath, string $stubName, array $replacements, string $entityName): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (file_exists($filePath)) {
            $output->writeln("<comment>{$entityName} sudah ada, pembuatan dilewati.</comment>");
            return;
        }
        
        $stubPath = __DIR__ . '/../../templates/' . $stubName;
        if (!file_exists($stubPath)) {
            throw new Exception("FATAL: Template `{$stubName}` tidak ditemukan di dalam library.");
        }
        $stubContent = file_get_contents($stubPath);
        
        // Mengganti semua placeholder dengan nilai yang sebenarnya
        $generatedContent = str_replace(array_keys($replacements), array_values($replacements), $stubContent);

        file_put_contents($filePath, $generatedContent);
        $output->writeln("<info>✅ {$entityName} berhasil dibuat di:</info> " . str_replace($this->projectRoot . '/', '', $filePath));
    }

    // --- Helper Methods ---

    private function studlyCase(string $value): string { return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value))); }
    private function singular(string $value): string { return rtrim($value, 's'); } // Implementasi dasar
    private function plural(string $value): string { return $value . 's'; } // Implementasi dasar

    private function parseTableName(string $sql): string
    {
        if (preg_match('/CREATE TABLE `?(\w+)`?/', $sql, $matches)) {
            return $matches[1];
        }
        throw new Exception("Tidak dapat mem-parsing nama tabel. Pastikan formatnya adalah 'CREATE TABLE `nama_tabel`'.");
    }

    private function parseColumns(string $sql): array
    {
        if (!preg_match('/\((.*)\)/s', $sql, $matches)) {
            throw new Exception("Struktur kolom di dalam kurung (...) tidak ditemukan atau tidak valid.");
        }
        
        $columns = [];
        $schema = $matches[1];
        // Memecah definisi kolom berdasarkan koma yang diikuti oleh baris baru
        $lines = preg_split('/,\s*\n/', $schema);
        $excludedKeywords = ['PRIMARY KEY', 'CONSTRAINT', 'UNIQUE KEY', 'KEY'];

        foreach ($lines as $line) {
            $line = trim($line);
            // Mencocokkan nama kolom yang diapit oleh backtick (`) atau tidak
            if (preg_match('/^`?(\w+)`?/', $line, $colMatches)) {
                $isKeywordLine = false;
                // Memeriksa apakah baris ini adalah definisi KEY, bukan kolom
                foreach ($excludedKeywords as $keyword) {
                    if (str_starts_with(strtoupper($line), $keyword)) {
                        $isKeywordLine = true;
                        break;
                    }
                }
                if (!$isKeywordLine) {
                    $columns[] = $colMatches[1];
                }
            }
        }
        
        if (empty($columns)) {
            throw new Exception("Tidak ada kolom yang berhasil diparsing. Periksa kembali sintaks file SQL Anda.");
        }
        return $columns;
    }
}