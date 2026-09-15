param(
	[string] $ZipPath = '',
	[string] $WordPressSource = 'C:\Users\Manzur\Local Sites\themezur\app\public',
	[string] $DatabaseHost = '127.0.0.1',
	[int] $DatabasePort = 10178,
	[string] $DatabaseUser = 'root',
	[string] $DatabasePassword = 'root',
	[string] $MySqlPath = '',
	[string] $PhpPath = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$pluginRoot = [System.IO.Path]::GetFullPath( ( Join-Path $PSScriptRoot '..' ) )

if ( [string]::IsNullOrWhiteSpace( $ZipPath ) ) {
	$versionHit = [regex]::Match(
		( Get-Content -LiteralPath ( Join-Path $pluginRoot 'plumberslot.php' ) -Raw ),
		"(?m)^\s*\* Version:\s*([0-9]+(?:\.[0-9]+){2})\s*$"
	)
	if ( -not $versionHit.Success ) {
		throw 'Could not read the PlumberSlot version.'
	}
	$ZipPath = Join-Path $pluginRoot ( 'dist\plumberslot-' + $versionHit.Groups[1].Value + '.zip' )
}

$releaseZip = [System.IO.Path]::GetFullPath( $ZipPath )
$wpSource   = [System.IO.Path]::GetFullPath( $WordPressSource )

if ( -not ( Test-Path -LiteralPath $releaseZip -PathType Leaf ) ) {
	throw "Release ZIP not found: $releaseZip"
}

if ( -not ( Test-Path -LiteralPath ( Join-Path $wpSource 'wp-settings.php' ) -PathType Leaf ) ) {
	throw "WordPress source is invalid: $wpSource"
}

if ( [string]::IsNullOrWhiteSpace( $PhpPath ) ) {
	$PhpPath = ( Get-Command php -ErrorAction Stop ).Source
}

if ( [string]::IsNullOrWhiteSpace( $MySqlPath ) ) {
	$serviceRoot = Join-Path $env:APPDATA 'Local\lightning-services'
	$MySqlPath   = Get-ChildItem -LiteralPath $serviceRoot -Recurse -File -Filter 'mysql.exe' |
		Sort-Object LastWriteTime -Descending |
		Select-Object -First 1 -ExpandProperty FullName
}

if ( -not ( Test-Path -LiteralPath $MySqlPath -PathType Leaf ) ) {
	throw 'MySQL client could not be located.'
}

$databaseName = 'plumberslot_smoke_' + [guid]::NewGuid().ToString( 'N' )
$temporaryBase = [System.IO.Path]::GetFullPath( [System.IO.Path]::GetTempPath() )
$temporaryRoot = [System.IO.Path]::GetFullPath(
	( Join-Path $temporaryBase ( 'plumberslot-wp-smoke-' + [guid]::NewGuid().ToString( 'N' ) ) )
)

if ( -not $temporaryRoot.StartsWith( $temporaryBase, [System.StringComparison]::OrdinalIgnoreCase ) ) {
	throw 'Disposable WordPress path escaped the system temporary directory.'
}

$databaseCreated = $false
$temporaryRemoved = $false
$databaseDropped = $false
$report = $null

try {
	New-Item -ItemType Directory -Path $temporaryRoot -Force | Out-Null

	Get-ChildItem -LiteralPath $wpSource -File | Where-Object { 'wp-config.php' -ne $_.Name } | ForEach-Object {
		Copy-Item -LiteralPath $_.FullName -Destination ( Join-Path $temporaryRoot $_.Name )
	}
	function Copy-DirectoryFast {
		param(
			[string] $Source,
			[string] $Destination
		)

		& robocopy.exe $Source $Destination /E /NFL /NDL /NJH /NJS /NP | Out-Null
		if ( 7 -lt $LASTEXITCODE ) {
			throw "Could not copy $Source into the disposable install."
		}
	}

	Copy-DirectoryFast ( Join-Path $wpSource 'wp-admin' ) ( Join-Path $temporaryRoot 'wp-admin' )
	Copy-DirectoryFast ( Join-Path $wpSource 'wp-includes' ) ( Join-Path $temporaryRoot 'wp-includes' )

	$contentRoot = Join-Path $temporaryRoot 'wp-content'
	New-Item -ItemType Directory -Path ( Join-Path $contentRoot 'plugins' ) -Force | Out-Null
	New-Item -ItemType Directory -Path ( Join-Path $contentRoot 'themes' ) -Force | Out-Null
	$defaultTheme = Join-Path $wpSource 'wp-content\themes\twentytwentyfive'
	if ( Test-Path -LiteralPath $defaultTheme -PathType Container ) {
		Copy-DirectoryFast $defaultTheme ( Join-Path $contentRoot 'themes\twentytwentyfive' )
	}

	Expand-Archive -LiteralPath $releaseZip -DestinationPath ( Join-Path $contentRoot 'plugins' )
	if ( -not ( Test-Path -LiteralPath ( Join-Path $contentRoot 'plugins\plumberslot\plumberslot.php' ) -PathType Leaf ) ) {
		throw 'Release ZIP did not install as wp-content/plugins/plumberslot/plumberslot.php.'
	}

	$mysqlArgs = @(
		'--protocol=TCP',
		"--host=$DatabaseHost",
		"--port=$DatabasePort",
		"--user=$DatabaseUser",
		"--password=$DatabasePassword",
		'--batch',
		'--skip-column-names'
	)

	& $MySqlPath @mysqlArgs --execute="CREATE DATABASE ``$databaseName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>$null
	if ( 0 -ne $LASTEXITCODE ) {
		throw 'Could not create the disposable database.'
	}
	$databaseCreated = $true

	$env:PLUMBERSLOT_SMOKE_WP_ROOT = $temporaryRoot.Replace( '\', '/' )
	$env:PLUMBERSLOT_SMOKE_DB_NAME = $databaseName
	$env:PLUMBERSLOT_SMOKE_DB_USER = $DatabaseUser
	$env:PLUMBERSLOT_SMOKE_DB_PASSWORD = $DatabasePassword
	$env:PLUMBERSLOT_SMOKE_DB_HOST = $DatabaseHost
	$env:PLUMBERSLOT_SMOKE_DB_PORT = [string] $DatabasePort
	$runner = Join-Path $pluginRoot 'scripts\release-smoke.php'

	function Invoke-SmokePhase {
		param( [string] $Phase )

		$output = @(& $PhpPath -d extension=php_mysqli.dll $runner $Phase 2>&1)
		$exitCode = $LASTEXITCODE
		$jsonLine = $output |
			ForEach-Object { [string] $_ } |
			Where-Object { $_.TrimStart().StartsWith( '{' ) } |
			Select-Object -Last 1

		if ( 0 -ne $exitCode -or [string]::IsNullOrWhiteSpace( $jsonLine ) ) {
			throw ( "Smoke phase '$Phase' failed: " + ( $output -join [Environment]::NewLine ) )
		}

		return $jsonLine | ConvertFrom-Json
	}

	$install    = Invoke-SmokePhase 'install'
	$verify     = Invoke-SmokePhase 'verify'
	$deactivate = Invoke-SmokePhase 'deactivate'

	$report = [pscustomobject]@{
		Zip                    = $releaseZip
		SHA256                 = ( Get-FileHash -LiteralPath $releaseZip -Algorithm SHA256 ).Hash.ToLowerInvariant()
		WordPressVersion       = $verify.wordpress_version
		Installed              = [bool] $install.installed
		Activated              = [bool] $install.active
		DatabaseVersionCurrent = [bool] $verify.checks.db_version
		Tables                 = [int] $verify.table_count
		ActionSchedulerLoaded  = [bool] $verify.checks.action_scheduler
		RestRoutes             = [int] $verify.rest_route_count
		ReleaseFilesPresent    = [bool] (
			$verify.checks.admin_asset -and
			$verify.checks.widget_asset -and
			$verify.checks.pot -and
			$verify.checks.security_policy -and
			$verify.checks.support_guide -and
			$verify.checks.third_party_notice
		)
		Deactivated            = [bool] $deactivate.deactivated
	}
} finally {
	Remove-Item Env:PLUMBERSLOT_SMOKE_WP_ROOT -ErrorAction SilentlyContinue
	Remove-Item Env:PLUMBERSLOT_SMOKE_DB_NAME -ErrorAction SilentlyContinue
	Remove-Item Env:PLUMBERSLOT_SMOKE_DB_USER -ErrorAction SilentlyContinue
	Remove-Item Env:PLUMBERSLOT_SMOKE_DB_PASSWORD -ErrorAction SilentlyContinue
	Remove-Item Env:PLUMBERSLOT_SMOKE_DB_HOST -ErrorAction SilentlyContinue
	Remove-Item Env:PLUMBERSLOT_SMOKE_DB_PORT -ErrorAction SilentlyContinue

	if ( $databaseCreated ) {
		$dropArgs = @(
			'--protocol=TCP',
			"--host=$DatabaseHost",
			"--port=$DatabasePort",
			"--user=$DatabaseUser",
			"--password=$DatabasePassword",
			'--batch',
			'--skip-column-names',
			"--execute=DROP DATABASE IF EXISTS ``$databaseName``;"
		)
		& $MySqlPath @dropArgs 2>$null
		$databaseDropped = 0 -eq $LASTEXITCODE
	}

	if ( Test-Path -LiteralPath $temporaryRoot -PathType Container ) {
		$resolvedTemporary = [System.IO.Path]::GetFullPath( $temporaryRoot )
		if ( $resolvedTemporary.StartsWith( $temporaryBase, [System.StringComparison]::OrdinalIgnoreCase ) -and
			( Split-Path $resolvedTemporary -Leaf ) -like 'plumberslot-wp-smoke-*' ) {
			Remove-Item -LiteralPath $resolvedTemporary -Recurse -Force
			$temporaryRemoved = -not ( Test-Path -LiteralPath $resolvedTemporary )
		}
	}
}

if ( $null -eq $report ) {
	throw 'Release smoke did not produce a report.'
}

$report | Add-Member -NotePropertyName DatabaseDropped -NotePropertyValue $databaseDropped
$report | Add-Member -NotePropertyName TemporaryInstallRemoved -NotePropertyValue $temporaryRemoved
$report
