<#
.SYNOPSIS
    Packages the centaur-graph-mail plugin as an installable WordPress ZIP.

.DESCRIPTION
    Reads the version from the plugin header and writes dist\centaur-graph-mail-<version>.zip.
    The archive contains a single top-level "centaur-graph-mail/" folder, which is what
    Plugins -> Add New -> Upload Plugin expects. Entries are written with forward slashes
    so the ZIP extracts correctly on Linux hosts (Compress-Archive on Windows PowerShell does not).

.PARAMETER OutputDirectory
    Where to write the ZIP. Defaults to .\dist next to this script.

.EXAMPLE
    .\build-zip.ps1
#>
[CmdletBinding()]
param(
    [string] $OutputDirectory = (Join-Path $PSScriptRoot 'dist')
)

$ErrorActionPreference = 'Stop'

$slug       = 'centaur-graph-mail'
$pluginDir  = Join-Path $PSScriptRoot $slug
$mainFile   = Join-Path $pluginDir "$slug.php"

if (-not (Test-Path $mainFile)) {
    throw "Plugin main file not found: $mainFile"
}

# Version from the "Version:" header, cross-checked with the CENTAUR_GRAPH_MAIL_VERSION constant.
$source = Get-Content -Raw $mainFile
$headerMatch = [regex]::Match($source, '(?m)^\s*\*\s*Version:\s*(\S+)')
if (-not $headerMatch.Success) {
    throw "No 'Version:' header found in $mainFile"
}
$version = $headerMatch.Groups[1].Value

$constantMatch = [regex]::Match($source, "define\(\s*'CENTAUR_GRAPH_MAIL_VERSION',\s*'([^']+)'")
if ($constantMatch.Success -and $constantMatch.Groups[1].Value -ne $version) {
    throw "Version mismatch: header says $version, CENTAUR_GRAPH_MAIL_VERSION says $($constantMatch.Groups[1].Value)"
}

# Files that never belong in a release.
$excludePatterns = @('.git*', '.DS_Store', 'Thumbs.db', '*.zip', '*.log', 'node_modules', 'tests', '.vscode', '.idea')

$files = Get-ChildItem -Path $pluginDir -Recurse -File -Force | Where-Object {
    $relative = $_.FullName.Substring($pluginDir.Length + 1)
    $segments = $relative -split '[\\/]'
    -not ($segments | Where-Object { $segment = $_; $excludePatterns | Where-Object { $segment -like $_ } })
}

New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null
$zipPath = Join-Path (Resolve-Path $OutputDirectory) "$slug-$version.zip"
if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($file in $files) {
        $entryName = "$slug/" + ($file.FullName.Substring($pluginDir.Length + 1) -replace '\\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $file.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
        Write-Verbose "Added $entryName"
    }
}
finally {
    $zip.Dispose()
}

Write-Host "Created $zipPath ($($files.Count) files, version $version)"
