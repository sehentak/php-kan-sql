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
 * Perintah utama untuk membaca skema SQL dan menghasilkan arsitektur Model-Repository(-Controller)
 * yang fungsional menggunakan PDO murni.
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
            ->setDescription('Membuat Model & Repository (dan opsional Controller) fungsional dari skema SQL.')
            ->addArgument('sql_file', InputArgument::REQUIRED, 'Path ke file skema .sql.')
            ->addOption(
                'setup',
                null,
                InputOption::VALUE_OPTIONAL,
                'Generate E2E setup (Controller, Router, dll). Pilihan: <fg=yellow>apache</>, <fg=yellow>nginx</>.',
                false
            );
    }

    /**
     * Logika utama yang akan dieksekusi saat perintah dijalankan.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $setupMode = $input->getOption('setup');
            if ($setupMode === null && $input->hasParameterOption('--setup')) {
                $setupMode = 'apache';
            }

            $sqlFilePath = $this->projectRoot . '/' . $input->getArgument('sql_file');
            $output->writeln("<info>Membaca file skema:</info> {$sqlFilePath}");
            if (!file_exists($sqlFilePath)) throw new Exception("File tidak ditemukan.");

            $sqlContent = file_get_contents($sqlFilePath);

            // Parsing informasi penting
            $tableName = $this->parseTableName($sqlContent);
            $columns = $this->parseColumns($sqlContent);
            $hasSoftDeletes = in_array('deleted_at', $columns);
            $modelName = $this->studlyCase($this->singular($tableName));
            
            $output->writeln("<comment>Tabel terdeteksi:</comment> {$tableName}");
            if ($hasSoftDeletes) $output->writeln("<comment>Fitur Soft Delete akan diaktifkan.</comment>");

            // Selalu generate file inti (Model & Repository)
            $this->generateModel($output, $modelName, $columns);
            $this->generateRepository($output, $modelName, $tableName, $columns, $hasSoftDeletes);
            
            // Jika mode setup aktif, generate lapisan HTTP (Controller & file pendukung)
            if ($setupMode !== false) {
                $output->writeln("\n<info>Mode --setup aktif. Menghasilkan lapisan HTTP...</info>");
                $this->generateController($output, $modelName);
                $this->generateDatabaseBootstrap($output);
                $this->generateRouter($output, $modelName);
                $this->generateEnvExample($output);

                switch ($setupMode) {
                    case 'apache':
                        $this->generateHtaccess($output);
                        break;
                    case 'nginx':
                        $this->generateNginxConfig($output);
                        break;
                    default:
                        $output->writeln("<warning>Pilihan setup '{$setupMode}' tidak valid. Tidak ada konfigurasi server yang dibuat.</warning>");
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
        $this->createFromStub($output, '/src/Models/' . $modelName . '.php', 'model.stub', [
            '{{ modelName }}' => $modelName,
            '{{ properties }}' => rtrim($properties),
        ], "Model `{$modelName}`");
    }

    /**
     * Menghasilkan file Repository berdasarkan template.
     */
    private function generateRepository(OutputInterface $output, string $modelName, string $tableName, array $columns, bool $hasSoftDeletes): void
    {
        $repositoryName = "{$modelName}Repository";
        $fillableColumns = array_diff($columns, ['id', 'created_at', 'updated_at', 'deleted_at']);
        
        $insertColumns = '`' . implode('`, `', $fillableColumns) . '`';
        $insertPlaceholders = rtrim(str_repeat('?, ', count($fillableColumns)), ', ');
        
        $updateSet = '';
        $executeArrayParamsCreate = '';
        $executeArrayParamsUpdate = '';

        foreach ($fillableColumns as $column) {
            $updateSet .= "`{$column}` = ?, ";
            $camelCaseColumn = $this->camelCase($column);
            $executeArrayParamsCreate .= "            \$model->{$camelCaseColumn},\n";
            $executeArrayParamsUpdate .= "            \$model->{$camelCaseColumn},\n";
        }
        
        $restoreMethod = '';
        if ($hasSoftDeletes) {
            $restoreStub = file_get_contents(__DIR__ . '/../../templates/stubs/repository_restore_method.stub');
            $restoreMethod = str_replace('{{ tableName }}', $tableName, $restoreStub);
        }

        $this->createFromStub($output, '/src/Repositories/' . $repositoryName . '.php', 'repository.stub', [
            '{{ repositoryName }}' => $repositoryName,
            '{{ modelName }}' => $modelName,
            '{{ modelNamespace }}' => 'App\\Models\\' . $modelName,
            '{{ tableName }}' => $tableName,
            '{{ softDeleteWhereClause }}' => $hasSoftDeletes ? "WHERE `deleted_at` IS NULL" : "",
            '{{ softDeleteAndWhereClause }}' => $hasSoftDeletes ? "AND `deleted_at` IS NULL" : "",
            '{{ insertColumns }}' => $insertColumns,
            '{{ insertPlaceholders }}' => $insertPlaceholders,
            '{{ executeArrayParamsCreate }}' => rtrim($executeArrayParamsCreate),
            '{{ updateSetClause }}' => rtrim($updateSet, ', '),
            '{{ executeArrayParamsUpdate }}' => rtrim($executeArrayParamsUpdate),
            '{{ deleteStatement }}' => $hasSoftDeletes ? "UPDATE `{$tableName}` SET `deleted_at` = NOW() WHERE `id` = ?" : "DELETE FROM `{$tableName}` WHERE `id` = ?",
            '{{ restoreMethod }}' => $restoreMethod,
        ], "Repository `{$repositoryName}`");
    }

    private function generateController(OutputInterface $output, string $modelName): void
    {
        $controllerName = "{$modelName}Controller";
        $repositoryName = "{$modelName}Repository";
        $this->createFromStub($output, '/src/Http/Controllers/' . $controllerName . '.php', 'controller.stub', [
            '{{ controllerName }}' => $controllerName,
            '{{ modelName }}' => $modelName,
            '{{ modelNamespace }}' => 'App\\Models\\' . $modelName,
            '{{ repositoryName }}' => $repositoryName,
            '{{ repositoryNamespace }}' => 'App\\Repositories\\' . $repositoryName,
            '{{ repositoryVariable }}' => lcfirst($repositoryName),
        ], "Controller `{$controllerName}`");
    }
    
    private function generateRouter(OutputInterface $output, string $modelName): void
    {
        $this->createFromStub($output, '/public/index.php', 'setup/router.php.stub', [
            '{{ modelName }}' => $modelName,
        ], "Router (index.php)");
    }

    private function generateDatabaseBootstrap(OutputInterface $output): void
    {
        $this->createFromStub($output, '/bootstrap/database.php', 'setup/database.php.stub', [], "Bootstrap Database");
    }

    private function generateEnvExample(OutputInterface $output): void
    {
        $this->createFromStub($output, '/.env.example', 'setup/env.example.stub', [], "Contoh Environment (.env.example)");
    }

    private function generateHtaccess(OutputInterface $output): void
    {
        $this->createFromStub($output, '/public/.htaccess', 'setup/htaccess.stub', [], "Konfigurasi Apache (.htaccess)");
    }

    private function generateNginxConfig(OutputInterface $output): void
    {
        $this->createFromStub($output, '/nginx.conf.example', 'setup/nginx.conf.stub', [], "Contoh Konfigurasi Nginx");
    }

    private function installDotenvDependency(OutputInterface $output): void
    {
        $composerJsonPath = $this->projectRoot . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            $output->writeln("<warning>composer.json tidak ditemukan. Melewati instalasi otomatis vlucas/phpdotenv.</warning>");
            return;
        }
        $composerConfig = json_decode(file_get_contents($composerJsonPath), true);
        if (isset($composerConfig['require']['vlucas/phpdotenv'])) {
            $output->writeln("<info>Dependensi vlucas/phpdotenv sudah terinstal.</info>");
            return;
        }
        $output->writeln("<info>Mencoba menginstal vlucas/phpdotenv...</info>");
        $command = 'composer require vlucas/phpdotenv --working-dir=' . escapeshellarg($this->projectRoot);
        shell_exec($command);
        clearstatcache();
        $composerConfig = json_decode(file_get_contents($composerJsonPath), true);
        if (isset($composerConfig['require']['vlucas/phpdotenv'])) {
            $output->writeln("<info>✅ vlucas/phpdotenv berhasil diinstal.</info>");
        } else {
            $output->writeln("<error>Gagal menginstal vlucas/phpdotenv secara otomatis.</error>");
        }
    }

    private function printSetupInstructions(OutputInterface $output): void
    {
        $output->writeln("\n<bg=blue;fg=white> LANGKAH SELANJUTNYA (SETUP) </>");
        $output->writeln("1. Salin <fg=yellow>.env.example</> menjadi <fg=yellow>.env</> dan isi kredensial database Anda.");
        $output->writeln("2. Dependensi <fg=cyan>vlucas/phpdotenv</> telah diinstal secara otomatis untuk Anda.");
        $output->writeln("3. Konfigurasikan web server Anda agar menunjuk ke direktori <fg=yellow>public</>.");
        $output->writeln("4. Untuk development, jalankan dari root proyek: <fg=yellow>php -S localhost:8000 -t public</>");
    }
    
    private function createFromStub(OutputInterface $output, string $filePath, string $stubName, array $replacements, string $entityName): void
    {
        $fullPath = $this->projectRoot . $filePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        if (file_exists($fullPath)) {
            $output->writeln("<comment>{$entityName} sudah ada, pembuatan dilewati.</comment>");
            return;
        }
        $stubPath = __DIR__ . '/../../templates/' . $stubName;
        if (!file_exists($stubPath)) throw new Exception("FATAL: Template `{$stubName}` tidak ditemukan.");
        $stubContent = file_get_contents($stubPath);
        $generatedContent = str_replace(array_keys($replacements), array_values($replacements), $stubContent);
        file_put_contents($fullPath, $generatedContent);
        $output->writeln("<info>✅ {$entityName} berhasil dibuat di:</info> " . ltrim($filePath, '/'));
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