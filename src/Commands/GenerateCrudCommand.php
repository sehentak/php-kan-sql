<?php

namespace Sehentak\PhpKanSql\Commands;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateCrudCommand extends Command
{
    private string $projectRoot;

    public function __construct(string $name = null)
    {
        parent::__construct($name);
        $this->projectRoot = getcwd();
    }

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
                false // Default value is false, meaning the option is not present
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $setupMode = $input->getOption('setup');
            // Jika option ada tapi tidak ada value, default ke 'apache'
            if ($setupMode === null && $input->hasParameterOption('--setup')) {
                $setupMode = 'apache';
            }

            $sqlFilePath = $this->projectRoot . '/' . $input->getArgument('sql_file');
            
            $output->writeln("<info>Membaca file skema:</info> {$sqlFilePath}");
            if (!file_exists($sqlFilePath)) throw new Exception("File tidak ditemukan.");

            $sqlContent = file_get_contents($sqlFilePath);

            $tableName = $this->parseTableName($sqlContent);
            $columns = $this->parseColumns($sqlContent);
            $hasSoftDeletes = in_array('deleted_at', $columns);
            
            $output->writeln("<comment>Tabel terdeteksi:</comment> {$tableName}");
            if ($hasSoftDeletes) $output->writeln("<comment>Fitur Soft Delete akan diaktifkan.</comment>");

            $modelName = $this->studlyCase($this->singular($tableName));
            
            $this->generateModel($output, $modelName, $columns);
            $this->generateController($output, $modelName, $tableName, $hasSoftDeletes);
            
            // Jika ada flag --setup, generate file pendukung
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
                        $output->writeln("<warning>Pilihan setup '{$setupMode}' tidak valid. Tidak ada konfigurasi server yang dibuat. Pilihan: apache, nginx.</warning>");
                        break;
                }
                $this->printSetupInstructions($output);
            }

            $output->writeln("\n<question>✅ Sukses! Kode fungsional telah berhasil dibuat.</question>");
            return Command::SUCCESS;

        } catch (Exception $e) {
            $output->writeln("\n<error>Sebuah kesalahan terjadi:</error> " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    // --- Generator Methods ---
    private function generateModel(OutputInterface $output, string $modelName, array $columns): void { /* ... */ }
    private function generateController(OutputInterface $output, string $modelName, string $tableName, bool $hasSoftDeletes): void { /* ... */ }
    private function generateDatabaseBootstrap(OutputInterface $output): void { /* ... */ }
    private function generateRouter(OutputInterface $output): void { /* ... */ }
    private function generateEnvExample(OutputInterface $output): void { /* ... */ }

    private function generateHtaccess(OutputInterface $output): void
    {
        $this->createFromStub($output, $this->projectRoot . '/public/.htaccess', 'setup/htaccess.stub', [], "Konfigurasi Apache (.htaccess)");
    }

    private function generateNginxConfig(OutputInterface $output): void
    {
        $this->createFromStub($output, $this->projectRoot . '/nginx.conf.example', 'setup/nginx.conf.stub', [], "Contoh Konfigurasi Nginx");
    }

    private function printSetupInstructions(OutputInterface $output): void
    {
        $output->writeln("\n<bg=blue;fg=white> LANGKAH SELANJUTNYA (SETUP) </>");
        $output->writeln("1. Salin `.env.example` menjadi `.env` dan isi kredensial database Anda.");
        $output->writeln("   <fg=yellow>cp .env.example .env</>");
        $output->writeln("2. Jalankan <fg=yellow>composer require vlucas/phpdotenv</> untuk membaca file .env.");
        $output->writeln("3. Konfigurasikan web server Anda (Apache/Nginx) agar menunjuk ke direktori <fg=yellow>public</>.");
        $output->writeln("4. Untuk development, jalankan server PHP bawaan:");
        $output->writeln("   <fg=yellow>php -S localhost:8000 -t public</>");
    }
    
    // --- Utility Methods ---
    private function createFromStub(OutputInterface $output, string $filePath, string $stubName, array $replacements, string $entityName): void { /* ... */ }
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