<?php

namespace Sehentak\PhpKanSql\Commands;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
            ->setDescription('Membuat file Model & Controller dari skema SQL dengan deteksi Soft Delete.')
            ->addArgument('sql_file', InputArgument::REQUIRED, 'Path ke file skema .sql dari root proyek.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $sqlFilePath = $this->projectRoot . '/' . $input->getArgument('sql_file');
            if (!file_exists($sqlFilePath)) {
                throw new Exception("File tidak ditemukan di path: {$sqlFilePath}");
            }

            $output->writeln("<info>Membaca file skema:</info> {$sqlFilePath}");
            $sqlContent = file_get_contents($sqlFilePath);

            $tableName = $this->parseTableName($sqlContent);
            $columns = $this->parseColumns($sqlContent);
            $fillableColumns = array_diff($columns, ['id', 'created_at', 'updated_at', 'deleted_at']);
            
            $hasSoftDeletes = in_array('deleted_at', $columns);
            if ($hasSoftDeletes) {
                $output->writeln("<comment>Kolom 'deleted_at' terdeteksi. Fitur Soft Delete akan diaktifkan.</comment>");
            }
            
            $output->writeln("<comment>Nama tabel terdeteksi:</comment> {$tableName}");

            $this->generateModel($output, $tableName, $fillableColumns, $hasSoftDeletes);
            $this->generateController($output, $tableName, $hasSoftDeletes);
            
            $output->writeln("\n<question>✅ Sukses! File Model dan Controller telah berhasil dibuat.</question>");
            return Command::SUCCESS;
        } catch (Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }

    private function generateModel(OutputInterface $output, string $tableName, array $fillableColumns, bool $hasSoftDeletes): void
    {
        $modelName = $this->studlyCase($this->singular($tableName));
        
        $softDeletesUse = $hasSoftDeletes ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n" : '';
        $softDeletesTrait = $hasSoftDeletes ? "    use SoftDeletes;\n" : '';

        $this->createFromStub(
            $output,
            $this->projectRoot . '/src/Models/' . $modelName . '.php',
            'model.stub',
            [
                '{{ softDeletesUseStatement }}' => $softDeletesUse,
                '{{ useSoftDeletesTrait }}' => $softDeletesTrait,
                '{{ modelName }}' => $modelName,
                '{{ tableName }}' => $tableName,
                '{{ fillableColumns }}' => "'" . implode("',\n        '", $fillableColumns) . "'",
            ],
            "Model `{$modelName}`"
        );
    }

    private function generateController(OutputInterface $output, string $tableName, bool $hasSoftDeletes): void
    {
        $modelName = $this->studlyCase($this->singular($tableName));
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

        $this->createFromStub(
            $output,
            $this->projectRoot . '/src/Http/Controllers/' . $controllerName . '.php',
            'controller.stub',
            [
                '{{ namespace }}' => 'App\Http\Controllers',
                '{{ modelNamespace }}' => 'App\Models\\' . $modelName,
                '{{ controllerName }}' => $controllerName,
                '{{ modelName }}' => $modelName,
                '{{ modelVariable }}' => lcfirst($modelName),
                '{{ modelVariablePlural }}' => $this->plural(lcfirst($modelName)),
                '{{ destroyComment }}' => $hasSoftDeletes ? 'Memindahkan resource ke tong sampah dengan mengisi `deleted_at` (soft delete).' : 'Menghapus resource secara permanen dari database.',
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
        if (!file_exists($stubPath)) throw new Exception("Template `{$stubName}` tidak ditemukan.");
        $stub = file_get_contents($stubPath);
        foreach ($replacements as $search => $replace) $stub = str_replace($search, $replace, $stub);
        file_put_contents($filePath, $stub);
        $output->writeln("<info>✅ {$entityName} berhasil dibuat di:</info> " . str_replace($this->projectRoot . '/', '', $filePath));
    }

    // --- Helper Methods ---
    private function studlyCase(string $value): string { return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value))); }
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
        return $columns;
    }
}