<?php

namespace Sehentak\PhpKanSql\Commands;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class GenerateCrudCommand
 * Perintah utama untuk membaca skema SQL dan menghasilkan file Model & Controller
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

    protected function configure(): void
    {
        $this
            ->setName('make:crud')
            ->setDescription('Membuat Model & Controller PDO fungsional dari skema SQL.')
            ->addArgument('sql_file', InputArgument::REQUIRED, 'Path ke file skema .sql dari root proyek.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $sqlFilePath = $this->projectRoot . '/' . $input->getArgument('sql_file');
            $output->writeln("<info>Membaca file skema:</info> {$sqlFilePath}");
            if (!file_exists($sqlFilePath)) throw new Exception("File tidak ditemukan.");

            $sqlContent = file_get_contents($sqlFilePath);

            $tableName = $this->parseTableName($sqlContent);
            $columns = $this->parseColumns($sqlContent);
            $output->writeln("<comment>Tabel terdeteksi:</comment> {$tableName}");

            $hasSoftDeletes = in_array('deleted_at', $columns);
            if ($hasSoftDeletes) $output->writeln("<comment>Kolom 'deleted_at' terdeteksi. Soft Delete akan diaktifkan.</comment>");

            $modelName = $this->studlyCase($this->singular($tableName));
            $fillableColumns = array_diff($columns, ['id', 'created_at', 'updated_at', 'deleted_at']);
            
            $this->generateModel($output, $modelName, $columns);
            $this->generateController($output, $modelName, $tableName, $fillableColumns, $hasSoftDeletes);
            
            $output->writeln("\n<question>✅ Sukses! Kode fungsional telah berhasil dibuat.</question>");
            return Command::SUCCESS;

        } catch (Exception $e) {
            $output->writeln("\n<error>Sebuah kesalahan terjadi:</error>");
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }
    }

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

    private function generateController(OutputInterface $output, string $modelName, string $tableName, array $fillableColumns, bool $hasSoftDeletes): void
    {
        $controllerName = "{$modelName}Controller";
        
        $insertColumns = '`' . implode('`, `', $fillableColumns) . '`';
        $insertPlaceholders = rtrim(str_repeat('?, ', count($fillableColumns)), ', ');
        
        $updateSet = '';
        foreach ($fillableColumns as $column) {
            $updateSet .= "`{$column}` = ?, ";
        }
        $updateSet = rtrim($updateSet, ', ');

        $extraMethods = $hasSoftDeletes 
            ? file_get_contents(__DIR__ . '/../../templates/stubs/restore_method.stub') 
            : '';

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