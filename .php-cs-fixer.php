<?php

// PHP-CS-Fixer configuration file
// Archivo de configuración para PHP-CS-Fixer

$finder = PhpCsFixer\Finder::create()
    // Include all PHP files in the current directory
    // Incluye todos los archivos PHP en el directorio actual
    ->in(__DIR__)
    
    // Exclude specific directories from linting
    // Excluye directorios específicos del análisis
    ->exclude([
        'vendor', // Dependencies / Dependencias
        'node_modules', // Node.js dependencies / Dependencias de Node.js
        'storage', // Cache or temporary files / Archivos de caché o temporales
        'bootstrap/cache', // Laravel cache directory / Directorio de caché de Laravel
    ])
    
    // Include only PHP files
    // Incluye solo archivos PHP
    ->name('*.php')
    
    // Exclude Blade templates for Laravel
    // Excluye plantillas Blade de Laravel
    ->notName('*.blade.php')
    
    // Ignore dotfiles (e.g., .env)
    // Ignora archivos ocultos (ej. .env)
    ->ignoreDotFiles(true)
    
    // Ignore version control system files (e.g., .git)
    // Ignora archivos de control de versiones (ej. .git)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    // Allow risky rules to enforce stricter standards
    // Permite reglas arriesgadas para estándares más estrictos
    ->setRiskyAllowed(true)
    
    // Define the rules for code linting
    // Define las reglas para el análisis del código
    ->setRules([
        '@PSR12' => true, // Enforce PSR-12 coding standards / Aplica estándares de codificación PSR-12
        'no_trailing_whitespace' => true, // Remove trailing whitespace / Elimina espacios en blanco finales
        'no_unused_imports' => true, // Remove unused imports / Elimina importaciones no utilizadas
        'ordered_imports' => ['sort_algorithm' => 'alpha'], // Sort imports alphabetically / Ordena importaciones alfabéticamente
        'phpdoc_align' => ['align' => 'left'], // Align PHPDoc tags / Alinea etiquetas PHPDoc
        'phpdoc_indent' => true, // Indent PHPDoc to match code / Indenta PHPDoc según el código
        'phpdoc_no_empty_return' => true, // Remove empty @return tags / Elimina etiquetas @return vacías
        'phpdoc_scalar' => true, // Use `int`, `bool`, `string` instead of `integer`, `boolean`, `real` / Usa `int`, `bool`, `string` en lugar de `integer`, `boolean`, `real`
        'phpdoc_separation' => true, // Add blank lines in PHPDoc / Agrega líneas en blanco en PHPDoc
        'phpdoc_trim' => true, // Remove unnecessary spaces in PHPDoc / Elimina espacios innecesarios en PHPDoc
        'semicolon_after_instruction' => true, // Ensure semicolons after instructions / Asegura punto y coma después de instrucciones
        'single_blank_line_at_eof' => true, // Ensure a single blank line at the end of files / Asegura una línea en blanco al final de los archivos
        'strict_param' => true, // Enforce strict parameter rules / Aplica reglas estrictas para parámetros
    ])
    
    // Apply the rules to the selected files
    // Aplica las reglas a los archivos seleccionados
    ->setFinder($finder);
