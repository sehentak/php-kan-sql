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
 * Generates a full Model-Repository-Controller architecture from an SQL schema,
 * with automatic foreign key detection and relationship handling.
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
            ->setDescription('Membuat arsitektur Model-Repository(-Controller) dengan deteksi relasi.')
            ->addArgument('sql_file', InputArgument::REQUIRED, 'Path ke file skema .sql.')
            ->addOption(
                'controller',
                null,
                InputOption::VALUE_NONE,
                'Generate Model, Repository, dan Controller dasar.'
            )
            ->addOption(
                'setup', null, InputOption::VALUE_OPTIONAL,
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
            $withController = $input->getOption('controller');
            $setupMode = $input->getOption('setup');
            if ($setupMode === null && $input->hasParameterOption('--setup')) {
                $setupMode = 'apache';
            }

            // Jika --setup digunakan, secara implisit --controller juga aktif
            if ($setupMode !== false) {
                $withController = true;
            }

            $sqlFilePath = $this->projectRoot . '/' . $input->getArgument('sql_file');
            $output->writeln("<info>Membaca file skema:</info> {$sqlFilePath}");
            if (!file_exists($sqlFilePath)) throw new Exception("File tidak ditemukan.");

            $sqlContent = file_get_contents($sqlFilePath);

            // --- Parsing Logic ---
            $tableName = $this->parseTableName($sqlContent);
            $columns = $this->parseColumns($sqlContent);
            $foreignKeys = $this->parseForeignKeys($sqlContent);
            $hasSoftDeletes = in_array('deleted_at', $columns);
            $modelName = $this->studlyCase($this->singular($tableName));
            
            $output->writeln("<comment>Tabel terdeteksi:</comment> {$tableName}");
            if ($hasSoftDeletes) $output->writeln("<comment>Fitur Soft Delete akan diaktifkan.</comment>");
            if (!empty($foreignKeys)) $output->writeln("<comment>Relasi Foreign Key terdeteksi.</comment>");
            
            // --- Generation Logic ---
            $this->generateModel($output, $modelName, $columns, $foreignKeys);
            $this->generateRepository($output, $modelName, $tableName, $columns, $hasSoftDeletes, $foreignKeys, dirname($sqlFilePath));
            
            if ($withController) {
                $this->generateController($output, $modelName, $hasSoftDeletes);
                $this->generateDatabaseBootstrap($output);
                $this->generateEnvExample($output);
                $this->installDotenvDependency($output);
            }

            if ($setupMode !== false) {
                $output->writeln("\n<info>Mode --setup aktif. Menghasilkan file pendukung...</info>");
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

    // --- Generator Methods ---

    private function generateModel(OutputInterface $output, string $modelName, array $columns, array $foreignKeys): void
    {
        $properties = '';
        $useStatements = '';
        $existingUse = [];

        foreach ($columns as $column) {
            $properties .= "    public ?string $" . $this->camelCase($column) . " = null;\n";
        }
        
        foreach ($foreignKeys as $fk) {
            $relatedModel = $this->studlyCase($this->singular($fk['parent_table']));
            $relationName = $this->camelCase($this->singular($fk['parent_table']));
            $properties .= "\n    /** @var \\App\\Models\\{$relatedModel}|null */\n";
            $properties .= "    public ?object \${$relationName} = null;\n";
            if (!isset($existingUse[$relatedModel])) {
                $useStatements .= "use App\\Models\\{$relatedModel};\n";
                $existingUse[$relatedModel] = true;
            }
        }

        $this->createFromStub($output, '/src/Models/' . $modelName . '.php', 'model_with_relations.stub', [
            '{{ modelName }}' => $modelName,
            '{{ useStatements }}' => $useStatements,
            '{{ properties }}' => rtrim($properties),
        ], "Model `{$modelName}`");
    }

    private function generateRepository(OutputInterface $output, string $modelName, string $tableName, array $columns, bool $hasSoftDeletes, array $foreignKeys, string $schemaDir): void
    {
        $repositoryName = "{$modelName}Repository";
        
        // --- Logic to build dynamic JOINs and SELECTs ---
        $selects = ["`{$tableName}`.*"];
        $joins = "";
        $hydrationLogic = "";
        $useStatements = "use App\\Models\\{$modelName};\n";
        $existingUse = [$modelName => true];
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

        foreach ($foreignKeys as $fk) {
            $parentTable = $fk['parent_table'];
            $parentModel = $this->studlyCase($this->singular($parentTable));
            $relationName = $this->camelCase($this->singular($parentTable));
            $fkPrefix = "{$relationName}_";

            if (!isset($existingUse[$parentModel])) {
                $useStatements .= "use App\\Models\\{$parentModel};\n";
                $existingUse[$parentModel] = true;
            }

            $joins .= "\n        LEFT JOIN `{$parentTable}` ON `{$tableName}`.`{$fk['column']}` = `{$parentTable}`.`id`";

            $parentSqlPath = "{$schemaDir}/{$parentTable}.sql";
            if (!file_exists($parentSqlPath)) {
                 $output->writeln("<warning>File skema untuk relasi '{$parentTable}' tidak ditemukan di '{$parentSqlPath}'. Relasi tidak akan di-hydrate.</warning>");
                 continue;
            }

            $parentSql = file_get_contents($parentSqlPath);
            $parentColumns = $this->parseColumns($parentSql);

            $hydrationLogic .= "\n        // Hydrate {$parentModel} relation\n";
            $hydrationLogic .= "        \${$relationName} = new {$parentModel}();\n";
            $hydrationLogic .= "        \$has{$parentModel}Data = false;\n";

            foreach ($parentColumns as $pCol) {
                $alias = $fkPrefix . $pCol;
                $selects[] = "`{$parentTable}`.`{$pCol}` as `{$alias}`";
                $camelCol = $this->camelCase($pCol);

                $hydrationLogic .= "        if (isset(\$row['{$alias}'])) {\n";
                $hydrationLogic .= "            \${$relationName}->{$camelCol} = \$row['{$alias}'];\n";
                $hydrationLogic .= "            \$has{$parentModel}Data = true;\n";
                $hydrationLogic .= "            unset(\$row['{$alias}']);\n";
                $hydrationLogic .= "        }\n";
            }
            $hydrationLogic .= "        if (\$has{$parentModel}Data) {\n";
            $hydrationLogic .= "            \$model->{$relationName} = \${$relationName};\n";
            $hydrationLogic .= "        }\n";
        }
        
        $restoreMethod = '';
        if ($hasSoftDeletes) {
            $restoreStub = file_get_contents(__DIR__ . '/../../templates/stubs/repository_restore_method.stub');
            $restoreMethod = str_replace('{{ tableName }}', $tableName, $restoreStub);
        }

        $this->createFromStub($output, '/src/Repositories/' . $repositoryName . '.php', 'repository_with_join.stub', [
            '{{ repositoryName }}' => $repositoryName,
            '{{ useStatements }}' => rtrim($useStatements),
            '{{ modelName }}' => $modelName,
            '{{ tableName }}' => $tableName,
            '{{ selectColumns }}' => implode(",\n           ", $selects),
            '{{ joinClause }}' => $joins,
            '{{ hydrationLogic }}' => $hydrationLogic,
            '{{ softDeleteWhereClause }}' => $hasSoftDeletes ? "WHERE `{$tableName}`.`deleted_at` IS NULL" : "",
            '{{ softDeleteAndWhereClause }}' => $hasSoftDeletes ? "AND `{$tableName}`.`deleted_at` IS NULL" : "",
            '{{ insertColumns }}' => $insertColumns,
            '{{ insertPlaceholders }}' => $insertPlaceholders,
            '{{ executeArrayParamsCreate }}' => rtrim($executeArrayParamsCreate),
            '{{ updateSetClause }}' => rtrim($updateSet, ', '),
            '{{ executeArrayParamsUpdate }}' => rtrim($executeArrayParamsUpdate),
            '{{ deleteStatement }}' => $hasSoftDeletes ? "UPDATE `{$tableName}` SET `deleted_at` = NOW() WHERE `id` = ?" : "DELETE FROM `{$tableName}` WHERE `id` = ?",
            '{{ restoreMethod }}' => $restoreMethod,
        ], "Repository `{$repositoryName}`");
    }

    private function generateController(OutputInterface $output, string $modelName, bool $hasSoftDeletes): void
    {
        $controllerName = "{$modelName}Controller";
        $repositoryName = "{$modelName}Repository";
        
        $restoreMethod = '';
        if ($hasSoftDeletes) {
            $restoreStub = file_get_contents(__DIR__ . '/../../templates/stubs/controller_restore_method.stub');
            $restoreMethod = str_replace('{{ repositoryVariable }}', lcfirst($repositoryName), $restoreStub);
        }

        $this->createFromStub($output, '/src/Http/Controllers/' . $controllerName . '.php', 'controller.stub', [
            '{{ controllerName }}' => $controllerName,
            '{{ modelName }}' => $modelName,
            '{{ modelNamespace }}' => 'App\\Models\\' . $modelName,
            '{{ repositoryName }}' => $repositoryName,
            '{{ repositoryNamespace }}' => 'App\\Repositories\\' . $repositoryName,
            '{{ repositoryVariable }}' => lcfirst($repositoryName),
            '{{ restoreMethod }}' => $restoreMethod,
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
        preg_match('/CREATE TABLE `[^`]+` \((.*?)\)[^\)]*;/s', $sql, $matches);
        if (!isset($matches[1])) {
            return [];
        }

        $lines = explode(",\n", $matches[1]);
        $columns = [];

        foreach ($lines as $line) {
            $line = trim($line);
            $upper = strtoupper($line);

            // Abaikan definisi non-kolom
            if (
                str_starts_with($upper, 'PRIMARY KEY') ||
                str_starts_with($upper, 'UNIQUE KEY') ||
                str_starts_with($upper, 'KEY') ||
                str_starts_with($upper, 'CONSTRAINT') ||
                str_starts_with($upper, 'INDEX') ||
                str_starts_with($upper, 'FOREIGN KEY')
            ) {
                continue;
            }

            if (preg_match('/^`([^`]+)`/', $line, $colMatch)) {
                $columns[] = $colMatch[1];
            }
        }
        if (empty($columns)) throw new Exception("Tidak ada kolom yang berhasil diparsing.");
        return $columns;
    }

    private function parseForeignKeys(string $sql): array
    {
        $keys = [];
        $pattern = '/CONSTRAINT `?\w+`? FOREIGN KEY \(`?(\w+)`?\) REFERENCES `?(\w+)`? \(`?(\w+)`?\)/';
        if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $keys[] = [
                    'column' => $match[1],
                    'parent_table' => $match[2],
                    'parent_column' => $match[3],
                ];
            }
        }
        return $keys;
    }
}