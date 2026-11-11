param(
  [Parameter(Mandatory = $true)]
  [ValidateSet('dev', 'local', 'lan')]
  [string]$Mode
)

$projectRoot = Split-Path -Parent $PSScriptRoot
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
if ($phpCommand) {
  $phpPath = $phpCommand.Source
} else {
  $packageRoot = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages'
  $phpPath = Get-ChildItem -Path $packageRoot -Directory -Filter 'PHP.PHP.8.3_*' -ErrorAction SilentlyContinue |
    ForEach-Object { Join-Path $_.FullName 'php.exe' } |
    Where-Object { Test-Path -LiteralPath $_ } |
    Select-Object -First 1
}

if (-not $phpPath) {
  throw 'PHP 8.3 is required. Install it with: winget install --id PHP.PHP.8.3'
}

$phpDirectory = Split-Path -Parent $phpPath
$phpIni = Join-Path $phpDirectory 'php.ini'
if (-not (Test-Path -LiteralPath $phpIni)) {
  $iniContent = @"
extension_dir="$phpDirectory\ext"
extension=openssl
extension=mbstring
extension=pdo_mysql
extension=pdo_sqlite
extension=sqlite3
extension=fileinfo
extension=curl
extension=sodium
extension=zip
upload_max_filesize=25M
post_max_size=27M
"@
  [System.IO.File]::WriteAllText($phpIni, $iniContent, [System.Text.Encoding]::ASCII)
}

$env:PHPRC = $phpDirectory
$env:Path = "$phpDirectory;$env:Path"
$phpModules = & $phpPath -m
$requiredModules = @('openssl', 'mbstring', 'pdo_mysql', 'fileinfo')
$missingModules = $requiredModules | Where-Object { $_ -notin $phpModules }
if ($missingModules) {
  throw "PHP is missing required extensions: $($missingModules -join ', '). Check $phpIni."
}

$port = if ($Mode -eq 'dev') { 8000 } else { 3001 }
$bindHost = if ($Mode -eq 'lan') { '0.0.0.0' } else { '127.0.0.1' }
$reverbHost = if ($Mode -eq 'lan') { '0.0.0.0' } else { '127.0.0.1' }
$reverbPort = 8091
$concurrently = Join-Path $projectRoot 'node_modules\.bin\concurrently.cmd'
$collectorScript = Join-Path $projectRoot 'scripts\zkteco-attendance-collector.cjs'
$nodeCommand = Get-Command node -ErrorAction SilentlyContinue
$artisanDirectory = Join-Path $projectRoot 'backend'

if (-not (Test-Path -LiteralPath $concurrently)) {
  throw 'Project dependencies are missing. Run npm install first.'
}
if (-not (Test-Path -LiteralPath (Join-Path $artisanDirectory 'vendor\autoload.php'))) {
  throw 'Laravel dependencies are missing. Run composer install in the backend directory.'
}
if (-not (Test-Path -LiteralPath (Join-Path $artisanDirectory '.env'))) {
  throw 'Laravel configuration is missing. Copy backend\.env.example to backend\.env and configure MySQL.'
}
if (-not $nodeCommand) {
  throw 'Node.js is required to collect attendance from ZKTeco terminals.'
}

Set-Location $artisanDirectory
& $concurrently -k -n api,realtime,attendance `
  "php artisan serve --host=$bindHost --port=$port --no-reload" `
  "php artisan reverb:start --host=$reverbHost --port=$reverbPort" `
  "node `"$collectorScript`""
exit $LASTEXITCODE