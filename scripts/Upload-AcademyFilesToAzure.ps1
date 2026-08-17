param(
    [Parameter(Mandatory = $true)]
    [string] $StagingDirectory,
    [string] $ResourceGroup = 'Cosmic-Academy_group',
    [string] $AppName = 'Cosmic-Academy',
    [string] $RemoteRoot = '/home/site/storage/app/private',
    [switch] $Execute,
    [string] $ConfirmTarget
)

$ErrorActionPreference = 'Stop'
$resolvedStaging = (Resolve-Path -LiteralPath $StagingDirectory).Path
$expectedConfirmation = "${AppName}:${RemoteRoot}"

function Get-SafeRelativePath {
    param(
        [Parameter(Mandatory = $true)]
        [string] $RootPath,
        [Parameter(Mandatory = $true)]
        [string] $FilePath
    )

    $separator = [IO.Path]::DirectorySeparatorChar
    $alternateSeparator = [IO.Path]::AltDirectorySeparatorChar
    $trimCharacters = [char[]] @($separator, $alternateSeparator)
    $fullRoot = [IO.Path]::GetFullPath($RootPath).TrimEnd($trimCharacters)
    $fullFile = [IO.Path]::GetFullPath($FilePath)
    $rootPrefix = $fullRoot + $separator

    if (-not $fullFile.StartsWith($rootPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw "File is outside the staging directory: $fullFile"
    }

    $relative = $fullFile.Substring($rootPrefix.Length)
    $relative = $relative.Replace($separator, [char] '/')
    if ($alternateSeparator -ne $separator) {
        $relative = $relative.Replace($alternateSeparator, [char] '/')
    }

    $segments = @($relative.Split('/'))
    if ([string]::IsNullOrWhiteSpace($relative) -or [IO.Path]::IsPathRooted($relative) -or
        $segments.Count -eq 0 -or @($segments | Where-Object { $_ -eq '' -or $_ -eq '.' -or $_ -eq '..' }).Count -gt 0) {
        throw "Unsafe relative path: $relative"
    }

    return $relative
}

function Resolve-AzureCliCommand {
    $command = Get-Command az -CommandType Application, ExternalScript -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($command) {
        return $command.Source
    }

    $candidates = @(
        'C:\Program Files\Microsoft SDKs\Azure\CLI2\wbin\az.cmd',
        'C:\Program Files (x86)\Microsoft SDKs\Azure\CLI2\wbin\az.cmd'
    )
    if ($env:LOCALAPPDATA) {
        $candidates += @(
            (Join-Path $env:LOCALAPPDATA 'Programs\Microsoft SDKs\Azure\CLI2\wbin\az.cmd'),
            (Join-Path $env:LOCALAPPDATA 'Programs\Azure CLI\wbin\az.cmd'),
            (Join-Path $env:LOCALAPPDATA 'Microsoft\AzureCLI\wbin\az.cmd')
        )
    }

    $uninstallRoots = @(
        'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\*'
    )
    $installLocations = @(Get-ItemProperty $uninstallRoots -ErrorAction SilentlyContinue |
        Where-Object { $_.DisplayName -like '*Azure CLI*' -and $_.InstallLocation } |
        Select-Object -ExpandProperty InstallLocation)
    foreach ($installLocation in $installLocations) {
        $candidates += (Join-Path $installLocation 'wbin\az.cmd')
        $candidates += (Join-Path $installLocation 'az.cmd')
    }

    foreach ($candidate in @($candidates | Select-Object -Unique)) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }

    return $null
}

function Resolve-AppServiceScmHostName {
    param(
        [Parameter(Mandatory = $true)]
        [string] $AzureCli,
        [Parameter(Mandatory = $true)]
        [string] $ResourceGroupName,
        [Parameter(Mandatory = $true)]
        [string] $WebAppName
    )

    $metadataJson = (& $AzureCli webapp show --resource-group $ResourceGroupName --name $WebAppName `
        --query '{defaultHostName:defaultHostName,enabledHostNames:enabledHostNames,hostNameSslStates:hostNameSslStates}' `
        --output json --only-show-errors)
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace(($metadataJson -join "`n"))) {
        throw "Could not retrieve App Service hostname metadata for $ResourceGroupName / $WebAppName."
    }

    try {
        $metadata = (($metadataJson -join "`n") | ConvertFrom-Json)
    } catch {
        throw "Azure CLI returned invalid App Service hostname metadata for $ResourceGroupName / $WebAppName."
    }

    $repositoryHosts = @($metadata.hostNameSslStates |
        Where-Object { $_.hostType -eq 'Repository' -and -not [string]::IsNullOrWhiteSpace($_.name) } |
        ForEach-Object { $_.name.Trim().ToLowerInvariant() } |
        Select-Object -Unique)
    if ($repositoryHosts.Count -eq 1) {
        return $repositoryHosts[0]
    }
    if ($repositoryHosts.Count -gt 1) {
        throw "Azure returned multiple Repository hostnames for $ResourceGroupName / ${WebAppName}: $($repositoryHosts -join ', ')."
    }

    $enabledScmHosts = @($metadata.enabledHostNames |
        Where-Object { $_ -and $_.Trim().ToLowerInvariant() -match '(^|\.)scm\.' } |
        ForEach-Object { $_.Trim().ToLowerInvariant() } |
        Select-Object -Unique)
    if ($enabledScmHosts.Count -eq 1) {
        return $enabledScmHosts[0]
    }
    if ($enabledScmHosts.Count -gt 1) {
        throw "Azure returned multiple enabled SCM hostnames for $ResourceGroupName / ${WebAppName}: $($enabledScmHosts -join ', ')."
    }

    $defaultHostName = [string] $metadata.defaultHostName
    $defaultHostName = $defaultHostName.Trim().ToLowerInvariant()
    if ($defaultHostName -notmatch '^(?<site>[^.]+)\.(?<suffix>(?:[^.]+\.)*azurewebsites\.net)$') {
        throw "Azure did not return an SCM hostname, and its default hostname cannot be safely converted: $defaultHostName"
    }

    return "$($Matches.site).scm.$($Matches.suffix)"
}

function Assert-ScmHostResolves {
    param(
        [Parameter(Mandatory = $true)]
        [string] $ScmHostName
    )

    if ($ScmHostName -notmatch '^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$' -or
        $ScmHostName -notmatch '(^|\.)scm\.' -or $ScmHostName -notmatch '\.azurewebsites\.net$') {
        throw "Azure returned an invalid SCM hostname: $ScmHostName"
    }

    try {
        $addresses = @([Net.Dns]::GetHostAddresses($ScmHostName))
    } catch {
        throw "SCM hostname '$ScmHostName' did not resolve in DNS: $($_.Exception.Message)"
    }
    if ($addresses.Count -eq 0) {
        throw "SCM hostname '$ScmHostName' did not resolve to any IP addresses."
    }
}

if ($RemoteRoot -ne '/home/site/storage/app/private') {
    throw 'RemoteRoot is restricted to /home/site/storage/app/private.'
}
if ($Execute -and $ConfirmTarget -cne $expectedConfirmation) {
    throw "Execution requires -ConfirmTarget '$expectedConfirmation'."
}

$azureCli = $null
$scmHostName = $null
if ($Execute) {
    $azureCli = Resolve-AzureCliCommand
    if (-not $azureCli) {
        throw @'
Azure CLI (az) is required for -Execute but is not installed or could not be found.
Install the official 64-bit Azure CLI with:
winget install --exact --id Microsoft.AzureCLI
Then close and reopen PowerShell, run az login, and retry. Dry-run mode does not require Azure CLI.
'@
    }
    Write-Host "Azure CLI: $azureCli"
    $scmHostName = Resolve-AppServiceScmHostName -AzureCli $azureCli -ResourceGroupName $ResourceGroup -WebAppName $AppName
    Assert-ScmHostResolves -ScmHostName $scmHostName
    Write-Host "SCM host: $scmHostName"
}

$files = @(Get-ChildItem -LiteralPath $resolvedStaging -Recurse -File)
Write-Host "Mode: $(if ($Execute) { 'EXECUTE' } else { 'DRY RUN' })"
Write-Host "Target: $ResourceGroup / $AppName / $RemoteRoot"
Write-Host "Files: $($files.Count); bytes: $(($files | Measure-Object -Property Length -Sum).Sum)"

$uploads = @()
$accessToken = $null
if ($Execute) {
    $accessToken = (& $azureCli account get-access-token --query accessToken --output tsv --only-show-errors)
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($accessToken)) {
        throw 'Could not acquire an Azure token for the Kudu overwrite-safety preflight.'
    }
}

foreach ($file in $files) {
    $relative = Get-SafeRelativePath -RootPath $resolvedStaging -FilePath $file.FullName
    $target = "$RemoteRoot/$relative"
    Write-Host "$($file.FullName) -> $target"
    if ($Execute) {
        $encodedPath = (($target.Substring('/home/'.Length).Split('/') | ForEach-Object {
            [Uri]::EscapeDataString($_)
        }) -join '/')
        $kuduUri = "https://$scmHostName/api/vfs/$encodedPath"
        $temporaryFile = [IO.Path]::GetTempFileName()
        try {
            try {
                Invoke-WebRequest -Uri $kuduUri -Headers @{ Authorization = "Bearer $accessToken" } `
                    -OutFile $temporaryFile -UseBasicParsing
                $localHash = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
                $remoteHash = (Get-FileHash -LiteralPath $temporaryFile -Algorithm SHA256).Hash
                if ($localHash -cne $remoteHash) {
                    throw "Refusing to overwrite a different Azure file at $target."
                }
                Write-Host "Already identical; skip: $target"
            } catch {
                $statusCode = $null
                if ($_.Exception.Response) {
                    $statusCode = [int] $_.Exception.Response.StatusCode
                }
                if ($statusCode -eq 404) {
                    $uploads += [PSCustomObject]@{ File = $file; Relative = $relative; Target = $target }
                } else {
                    throw
                }
            }
        } finally {
            Remove-Item -LiteralPath $temporaryFile -Force -ErrorAction SilentlyContinue
        }
    }
}

if ($Execute) {
    foreach ($upload in $uploads) {
        & $azureCli webapp deploy --resource-group $ResourceGroup --name $AppName --src-path $upload.File.FullName `
            --type static --target-path $upload.Target --clean false --restart false --only-show-errors
        if ($LASTEXITCODE -ne 0) {
            throw "Azure upload failed for $($upload.Relative)."
        }
    }
    Write-Host 'Upload calls completed. Run php artisan academy-files:validate in the App Service SSH console before cutover.'
} else {
    Write-Host "No Azure calls were made. Re-run with -Execute -ConfirmTarget '$expectedConfirmation' after reviewing this inventory."
}
