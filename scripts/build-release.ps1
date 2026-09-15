param(
	[string] $OutputDirectory
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$pluginRoot = [System.IO.Path]::GetFullPath( ( Join-Path $PSScriptRoot '..' ) )

if ( [string]::IsNullOrWhiteSpace( $OutputDirectory ) ) {
	$OutputDirectory = Join-Path $pluginRoot 'dist'
}

$outputRoot = [System.IO.Path]::GetFullPath( $OutputDirectory )
$mainFile   = Join-Path $pluginRoot 'tutorslot.php'
$mainSource = Get-Content -LiteralPath $mainFile -Raw
$versionHit = [regex]::Match( $mainSource, "(?m)^\s*\* Version:\s*([0-9]+(?:\.[0-9]+){2})\s*$" )

if ( -not $versionHit.Success ) {
	throw 'Could not read the plugin version from tutorslot.php.'
}

$version        = $versionHit.Groups[1].Value
$schedulerRoot  = Join-Path $pluginRoot 'vendor\woocommerce\action-scheduler'
$schedulerEntry = Join-Path $schedulerRoot 'action-scheduler.php'
$schedulerLock  = ( Get-Content -LiteralPath ( Join-Path $pluginRoot 'composer.lock' ) -Raw | ConvertFrom-Json ).packages |
	Where-Object { 'woocommerce/action-scheduler' -eq $_.name } |
	Select-Object -First 1

if ( $null -eq $schedulerLock ) {
	throw 'Action Scheduler is missing from composer.lock.'
}

if ( -not ( Test-Path -LiteralPath $schedulerEntry -PathType Leaf ) ) {
	throw 'The locked Action Scheduler runtime is not installed. Run Composer install before building.'
}

$schedulerSource = Get-Content -LiteralPath $schedulerEntry -Raw
$schedulerHit    = [regex]::Match( $schedulerSource, '(?m)^\s*\* Version:\s*([0-9]+(?:\.[0-9]+){2})\s*$' )
$lockedVersion   = [string] $schedulerLock.version

if ( $lockedVersion.StartsWith( 'v' ) ) {
	$lockedVersion = $lockedVersion.Substring( 1 )
}

if ( -not $schedulerHit.Success -or $lockedVersion -ne $schedulerHit.Groups[1].Value ) {
	throw "Installed Action Scheduler does not match composer.lock ($lockedVersion)."
}

$rules = Get-Content -LiteralPath ( Join-Path $pluginRoot '.distignore' ) |
	ForEach-Object { $_.Trim() } |
	Where-Object { '' -ne $_ -and -not $_.StartsWith( '#' ) }

function Test-DistExcluded {
	param( [string] $RelativePath )

	$normalized = '/' + $RelativePath.Replace( '\', '/' ).TrimStart( '/' )

	foreach ( $rule in $rules ) {
		$normalizedRule = $rule.Replace( '\', '/' ).TrimEnd( '/' )
		if ( $normalized -eq $normalizedRule -or $normalized.StartsWith( $normalizedRule + '/', [System.StringComparison]::OrdinalIgnoreCase ) ) {
			return $true
		}
	}

	return $false
}

$temporaryRoot = [System.IO.Path]::GetFullPath(
	( Join-Path ([System.IO.Path]::GetTempPath()) ( 'tutorslot-release-' + [guid]::NewGuid().ToString( 'N' ) ) )
)
$systemTemp = [System.IO.Path]::GetFullPath( [System.IO.Path]::GetTempPath() )

if ( -not $temporaryRoot.StartsWith( $systemTemp, [System.StringComparison]::OrdinalIgnoreCase ) ) {
	throw 'Release staging path escaped the system temporary directory.'
}

$stagePlugin = Join-Path $temporaryRoot 'tutorslot'
$zipPath     = Join-Path $outputRoot ( "tutorslot-$version.zip" )

try {
	New-Item -ItemType Directory -Path $stagePlugin -Force | Out-Null

	$directories = [System.Collections.Generic.Stack[string]]::new()
	$directories.Push( $pluginRoot )

	while ( 0 -lt $directories.Count ) {
		$currentDirectory = $directories.Pop()

		Get-ChildItem -LiteralPath $currentDirectory -File -Force | ForEach-Object {
			$relative = [System.IO.Path]::GetRelativePath( $pluginRoot, $_.FullName )
			if ( Test-DistExcluded $relative ) {
				return
			}

			$destination = Join-Path $stagePlugin $relative
			$parent      = Split-Path -Parent $destination
			New-Item -ItemType Directory -Path $parent -Force | Out-Null
			Copy-Item -LiteralPath $_.FullName -Destination $destination
		}

		Get-ChildItem -LiteralPath $currentDirectory -Directory -Force | ForEach-Object {
			$relative = [System.IO.Path]::GetRelativePath( $pluginRoot, $_.FullName )
			if ( -not ( Test-DistExcluded $relative ) -and -not ( $_.Attributes -band [System.IO.FileAttributes]::ReparsePoint ) ) {
				$directories.Push( $_.FullName )
			}
		}
	}

	$stageScheduler = Join-Path $stagePlugin 'vendor\woocommerce\action-scheduler'
	New-Item -ItemType Directory -Path ( Split-Path -Parent $stageScheduler ) -Force | Out-Null
	Copy-Item -LiteralPath $schedulerRoot -Destination $stageScheduler -Recurse

	$requiredFiles = @(
		'tutorslot.php',
		'uninstall.php',
		'readme.txt',
		'SECURITY.md',
		'SUPPORT.md',
		'THIRD-PARTY-LICENSES.txt',
		'languages\tutorslot.pot',
		'assets\dist\admin.js',
		'assets\dist\widget.js',
		'vendor\woocommerce\action-scheduler\action-scheduler.php',
		'vendor\woocommerce\action-scheduler\license.txt'
	)

	foreach ( $required in $requiredFiles ) {
		if ( -not ( Test-Path -LiteralPath ( Join-Path $stagePlugin $required ) -PathType Leaf ) ) {
			throw "Required release file is missing: $required"
		}
	}

	$forbidden = @(
		'assets\src', '.codex-tools', '.git', '.github', '.wordpress-org', 'node_modules',
		'tests', 'scripts', 'composer.json', 'composer.lock', 'package.json',
		'package-lock.json', 'phpunit.xml.dist', 'playwright.config.js'
	)

	foreach ( $path in $forbidden ) {
		if ( Test-Path -LiteralPath ( Join-Path $stagePlugin $path ) ) {
			throw "Development-only path leaked into the release: $path"
		}
	}

	$unexpectedVendor = Get-ChildItem -LiteralPath ( Join-Path $stagePlugin 'vendor' ) -Directory -Recurse |
		Where-Object {
			$relative = [System.IO.Path]::GetRelativePath( ( Join-Path $stagePlugin 'vendor' ), $_.FullName ).Replace( '\', '/' )
			$relative -notin @('woocommerce', 'woocommerce/action-scheduler') -and
			-not $relative.StartsWith( 'woocommerce/action-scheduler/' )
		}

	if ( $unexpectedVendor ) {
		throw 'A development Composer package leaked into the release vendor directory.'
	}

	$phpCommand  = ( Get-Command php -ErrorAction Stop ).Source
	$phpFiles    = @(Get-ChildItem -LiteralPath $stagePlugin -Recurse -File -Filter '*.php')
	$phpFailures = [System.Collections.Generic.List[string]]::new()

	foreach ( $phpFile in $phpFiles ) {
		& $phpCommand -n -l $phpFile.FullName *> $null
		if ( 0 -ne $LASTEXITCODE ) {
			$phpFailures.Add( [System.IO.Path]::GetRelativePath( $stagePlugin, $phpFile.FullName ) )
		}
	}

	if ( 0 -lt $phpFailures.Count ) {
		throw ( 'PHP syntax validation failed: ' + ( $phpFailures -join ', ' ) )
	}

	New-Item -ItemType Directory -Path $outputRoot -Force | Out-Null
	if ( Test-Path -LiteralPath $zipPath -PathType Leaf ) {
		$resolvedZip = [System.IO.Path]::GetFullPath( $zipPath )
		if ( -not $resolvedZip.StartsWith( $outputRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase ) ) {
			throw 'Refusing to replace a ZIP outside the requested output directory.'
		}
		Remove-Item -LiteralPath $resolvedZip -Force
	}

	Add-Type -AssemblyName System.IO.Compression.FileSystem
	[System.IO.Compression.ZipFile]::CreateFromDirectory(
		$temporaryRoot,
		$zipPath,
		[System.IO.Compression.CompressionLevel]::Optimal,
		$false
	)

	$archive   = [System.IO.Compression.ZipFile]::OpenRead( $zipPath )
	$entries   = @($archive.Entries)
	$topLevels = @($entries |
		Where-Object { '' -ne $_.FullName } |
		ForEach-Object { $_.FullName.Split( '/' )[0] } |
		Sort-Object -Unique)
	$archive.Dispose()

	if ( 1 -ne $topLevels.Count -or 'tutorslot' -ne $topLevels[0] ) {
		throw 'Release ZIP must contain exactly one top-level tutorslot directory.'
	}

	$hash = ( Get-FileHash -LiteralPath $zipPath -Algorithm SHA256 ).Hash.ToLowerInvariant()
	[pscustomobject]@{
		Path                   = $zipPath
		Version                = $version
		ActionSchedulerVersion = $lockedVersion
		Files                  = $entries.Count
		PhpFilesValidated      = $phpFiles.Count
		Bytes                  = ( Get-Item -LiteralPath $zipPath ).Length
		SHA256                 = $hash
	}
} finally {
	if ( Test-Path -LiteralPath $temporaryRoot -PathType Container ) {
		$resolvedTemporary = [System.IO.Path]::GetFullPath( $temporaryRoot )
		if ( $resolvedTemporary.StartsWith( $systemTemp, [System.StringComparison]::OrdinalIgnoreCase ) -and
			$resolvedTemporary -like '*tutorslot-release-*' ) {
			Remove-Item -LiteralPath $resolvedTemporary -Recurse -Force
		}
	}
}
