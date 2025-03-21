<?php

if (count($argv) < 2) {
    echo "Usage: php create-facade-enum.php <EnumNamespace>\n";
    echo "Example: php create-facade-enum.php \\Models\\UserStatus\n";
    exit(1);
}

$namespaceEnum = 'Models\\' . $argv[1];
$path = getcwd();

// Process path and namespace
$split = explode("\\", $namespaceEnum);
$enum = end($split);
$enum = str_replace('Enum', '', $enum);

$getComposerContent = file_get_contents($path . '/composer.json');
$composer = json_decode($getComposerContent, true);
$initialNamespace = array_keys($composer['autoload']['psr-4'])[0];

$packageName = $composer['name'] ?? '';
$packageParts = explode('/', $packageName);
$packageLastPart = end($packageParts);

$namespace = $initialNamespace . $namespaceEnum;

$enumSnakeUpperCase = strtoupper($enum);
$enumLowercase = strtolower($enum);
$enumSnakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $enum));
$enumSnakeUpperCase = strtoupper($enumSnakeCase);

// Determine config file based on enum namespace
$configKey = determineConfigKey($namespaceEnum, $packageLastPart, $path);

echo "Creating facade for enum: $enum\n";
echo "Enum key: $enumSnakeUpperCase\n";
echo "Config key: $configKey\n";

// 1. Create facade file
createFacadeFile($path, $initialNamespace, $namespace, $enum, $enumSnakeUpperCase);

// 2. Add constant to facades.php
addEnumConstant($path, $enumSnakeUpperCase, $enumSnakeCase);

// 3. Add binding to service provider
$serviceProviderPath = findServiceProvider($path, $initialNamespace);
if ($serviceProviderPath) {
    addBindingToServiceProvider($serviceProviderPath, $enumSnakeUpperCase, $configKey, $enumSnakeCase);
} else {
    echo "Warning: Could not find service provider. You'll need to add the binding manually.\n";
    echo "Binding code to add: \n";
    echo "\$this->app->bind('$enumSnakeCase-enum', function () {
    return config('$configKey.' . {$enumSnakeUpperCase} . '-namespace');
});\n";
}

// 4. Add entry to config file
addConfigEntry($path, $configKey, $enumSnakeUpperCase, $namespace, $namespaceEnum);

echo "Facade for enum $enum created successfully!\n";

/**
 * Create the facade file using stub
 */
function createFacadeFile($path, $initialNamespace, $namespace, $enum, $enumSnakeUpperCase) {
    // Create Facades directory if it doesn't exist
    $facadesDir = $path . '/src/Facades';
    
    if (!file_exists($facadesDir)) {
        mkdir($facadesDir, 0755, true);
        echo "Created directory: $facadesDir\n";
    }

    $suffix = strpos($enum, 'Enum') === false ? 'Enum' : '';
    
    $facadePath = $facadesDir . "/{$enum}{$suffix}.php";
    
    // Check if we have a stub file first
    $stubPath = $path . '/FacadeEnum.stub';
    if (file_exists($stubPath)) {
        $facadeContent = file_get_contents($stubPath);
        $facadeContent = str_replace('{EnumName}', $enum, $facadeContent);
        $facadeContent = str_replace('{UppercaseEnumName}', $enumSnakeUpperCase, $facadeContent);
        
        // Add namespace to @mixin if applicable
        if (strpos($facadeContent, '@mixin ') !== false && strpos($facadeContent, '@mixin ' . $namespace) === false) {
            $facadeContent = str_replace('@mixin ', '@mixin ' . $namespace, $facadeContent);
        }
    } else {
        // Create facade from template if stub doesn't exist
        $facadeContent = "<?php

namespace {$initialNamespace}Facades;

use Kompo\Auth\Facades\FacadeEnum;

/**
 * @mixin \\{$namespace}
 */
class {$enum}Enum extends FacadeEnum
{
    protected static function getFacadeAccessor()
    {
        return {$enumSnakeUpperCase}_ENUM_KEY;
    }
}";
    }
    
    file_put_contents($facadePath, $facadeContent);
    echo "Created facade file: $facadePath\n";
}

/**
 * Add enum constant to facades.php helper
 */
function addEnumConstant($path, $enumSnakeUpperCase, $enumSnakeCase) {
    // Create Helpers directory if it doesn't exist
    $helpersDir = $path . '/src/Helpers';
    
    if (!file_exists($helpersDir)) {
        mkdir($helpersDir, 0755, true);
        echo "Created directory: $helpersDir\n";
    }
    
    $facadesHelperPath = $helpersDir . '/facades.php';
    
    // Create file if it doesn't exist
    if (!file_exists($facadesHelperPath)) {
        file_put_contents($facadesHelperPath, "<?php\n\n");
        echo "Created facades.php helper file\n";
    }
    
    $facadesContent = file_get_contents($facadesHelperPath);
    
    // Check if constant already exists
    if (strpos($facadesContent, $enumSnakeUpperCase . '_ENUM_KEY') === false) {
        $constant = "const {$enumSnakeUpperCase}_ENUM_KEY = '{$enumSnakeCase}-enum';\n";
        
        // Add the constant
        $newContent = $facadesContent . $constant;
        file_put_contents($facadesHelperPath, $newContent);
        
        echo "Added constant {$enumSnakeUpperCase}_ENUM_KEY to facades.php\n";
    } else {
        echo "Warning: Constant {$enumSnakeUpperCase}_ENUM_KEY already exists in facades.php\n";
    }
}

/**
 * Find service provider file in the package
 */
function findServiceProvider($path, $initialNamespace) {
    $srcPath = $path . '/src';
    $serviceProviders = glob($srcPath . '/*ServiceProvider.php');
    
    if (empty($serviceProviders)) {
        return null;
    }
    
    return $serviceProviders[0]; // Return the first service provider found
}

/**
 * Add binding to the service provider
 */
function addBindingToServiceProvider($serviceProviderPath, $enumSnakeUpperCase, $configKey, $enumSnakeCase) {
    $serviceProviderContent = file_get_contents($serviceProviderPath);
    
    // Check if binding already exists
    if (strpos($serviceProviderContent, "{$enumSnakeUpperCase}_ENUM_KEY") === false) {
        // Find the register method
        $registerMethod = "public function register";
        $registerMethodPos = strpos($serviceProviderContent, $registerMethod);
        
        if ($registerMethodPos === false) {
            // If register method doesn't exist, try to find boot method
            $registerMethod = "public function boot";
            $registerMethodPos = strpos($serviceProviderContent, $registerMethod);
        }
        
        if ($registerMethodPos !== false) {
            // Find the end of the method (the next method or class end)
            $nextMethodPos = strpos($serviceProviderContent, "public function", $registerMethodPos + strlen($registerMethod));
            if ($nextMethodPos === false) {
                $nextMethodPos = strrpos($serviceProviderContent, "}");
            }
            
            if ($nextMethodPos !== false) {
                // Look for existing bindings
                $lastBindingPos = strrpos(
                    substr($serviceProviderContent, $registerMethodPos, $nextMethodPos - $registerMethodPos), 
                    '$this->app->bind'
                );
                
                if ($lastBindingPos !== false) {
                    $lastBindingPos += $registerMethodPos; // Adjust for the offset
                    
                    // Find the end of this binding (the semicolon or closing brace)
                    $bindingEndPos = strpos($serviceProviderContent, "});", $lastBindingPos);
                    if ($bindingEndPos !== false) {
                        $bindingEndPos += 3; // Include the closing characters
                    } else {
                        $bindingEndPos = strpos($serviceProviderContent, ";", $lastBindingPos);
                        if ($bindingEndPos !== false) {
                            $bindingEndPos += 1;
                        }
                    }
                    
                    if ($bindingEndPos) {
                        // Add our new binding
                        $binding = "\n\n        \$this->app->bind({$enumSnakeUpperCase}_ENUM_KEY, function () {
            return config('{$configKey}.{$enumSnakeCase}-namespace');
        });";
                        
                        $newContent = substr($serviceProviderContent, 0, $bindingEndPos) . 
                                      $binding . 
                                      substr($serviceProviderContent, $bindingEndPos);
                                      
                        file_put_contents($serviceProviderPath, $newContent);
                        
                        echo "Added binding for {$enumSnakeCase}-enum to service provider\n";
                    }
                } else {
                    // No existing bindings, add the first one
                    $methodBody = strpos($serviceProviderContent, "{", $registerMethodPos);
                    if ($methodBody !== false) {
                        $methodBodyEnd = $methodBody + 1;
                        
                        $binding = "\n        \$this->app->bind('{$enumSnakeUpperCase}_MODEL_KEY', function () {
            return config('{$configKey}.{$enumSnakeCase}-namespace');
        });";
                        
                        $newContent = substr($serviceProviderContent, 0, $methodBodyEnd) . 
                                      $binding . 
                                      substr($serviceProviderContent, $methodBodyEnd);
                                      
                        file_put_contents($serviceProviderPath, $newContent);
                        
                        echo "Added first binding for {$enumSnakeCase}-enum to service provider\n";
                    }
                }
            }
        }
    } else {
        echo "Warning: Binding for {$enumSnakeCase}-enum already exists in service provider\n";
    }
}

/**
 * Add entry to appropriate config file
 */
function addConfigEntry($path, $configKey, $enumSnakeUpperCase, $namespace, $namespaceEnum) {
    // First check if config directory exists
    $configDir = $path . '/config';
    if (!file_exists($configDir)) {
        mkdir($configDir, 0755, true);
        echo "Created config directory: $configDir\n";
    }
    
    $configPath = $configDir . "/{$configKey}.php";
    
    if (!file_exists($configPath)) {
        // Create a basic config file if it doesn't exist
        $configContent = "<?php\n\nreturn [\n    // Generated by create-facade-enum.php script\n];\n";
        file_put_contents($configPath, $configContent);
        echo "Created config file: $configPath\n";
    }
    
    $configContent = file_get_contents($configPath);
    
    $configEntryKey = "{$enumSnakeUpperCase}_ENUM_KEY . '-namespace'";
    
    if (strpos($configContent, $configEntryKey) === false) {
        // Find the return array
        $returnPos = strpos($configContent, "return [");
        
        if ($returnPos !== false) {
            $configEntry = "\n    {$configEntryKey} => getAppClass(App\Models\\{$namespaceEnum}::class, \\{$namespace}::class),";
            
            // Find a good place to insert the config entry
            $insertPos = strrpos($configContent, "];");
            
            if ($insertPos !== false) {
                $newContent = substr($configContent, 0, $insertPos) . $configEntry . "\n" . substr($configContent, $insertPos);
                file_put_contents($configPath, $newContent);
                echo "Added config entry for {$enumSnakeUpperCase}-enum-namespace to {$configKey}.php\n";
            }
        }
    } else {
        echo "Warning: Config entry for {$enumSnakeUpperCase}-enum-namespace already exists in {$configKey}.php\n";
    }
}

/**
 * Determine which config file to use - get the first config file with kompo- or condoedge- prefix
 */
function determineConfigKey($namespaceEnum, $packageLastPart, $path) {
    // Check for existing config files with kompo- prefix
    $kompoConfigFiles = glob($path . '/config/kompo-*.php');
    
    // Check for existing config files with condoedge- prefix
    $condoedgeConfigFiles = glob($path . '/config/condoedge-*.php');
    
    // Merge both arrays
    $configFiles = array_merge($kompoConfigFiles, $condoedgeConfigFiles);
    
    // If we have any config files, use the first one
    if (!empty($configFiles)) {
        return basename(reset($configFiles), '.php');
    }
    
    // Default config key based on package name if no config files found
    if (strpos($packageLastPart, 'condoedge') !== false) {
        return 'condoedge-' . str_replace('condoedge-', '', $packageLastPart);
    }
    
    return 'kompo-' . $packageLastPart;
}