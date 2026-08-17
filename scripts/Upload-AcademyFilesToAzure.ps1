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

if ($RemoteRoot -ne '/home/site/storage/app/private') {
    throw 'RemoteRoot is restricted to /home/site/storage/app/private.'
}
if ($Execute -and $ConfirmTarget -cne $expectedConfirmation) {
    throw "Execution requires -ConfirmTarget '$expectedConfirmation'."
}

$files = @(Get-ChildItem -LiteralPath $resolvedStaging -Recurse -File)
Write-Host "Mode: $(if ($Execute) { 'EXECUTE' } else { 'DRY RUN' })"
Write-Host "Target: $ResourceGroup / $AppName / $RemoteRoot"
Write-Host "Files: $($files.Count); bytes: $(($files | Measure-Object -Property Length -Sum).Sum)"

$uploads = @()
$accessToken = $null
if ($Execute) {
    $accessToken = (& az account get-access-token --query accessToken --output tsv --only-show-errors)
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($accessToken)) {
        throw 'Could not acquire an Azure token for the Kudu overwrite-safety preflight.'
    }
}

foreach ($file in $files) {
    $relative = [IO.Path]::GetRelativePath($resolvedStaging, $file.FullName).Replace('\\', '/')
    if ($relative.StartsWith('../') -or [IO.Path]::IsPathRooted($relative)) {
        throw "Unsafe relative path: $relative"
    }
    $target = "$RemoteRoot/$relative"
    Write-Host "$($file.FullName) -> $target"
    if ($Execute) {
        $encodedPath = (($target.Substring('/home/'.Length).Split('/') | ForEach-Object {
            [Uri]::EscapeDataString($_)
        }) -join '/')
        $kuduUri = "https://$AppName.scm.azurewebsites.net/api/vfs/$encodedPath"
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
        & az webapp deploy --resource-group $ResourceGroup --name $AppName --src-path $upload.File.FullName `
            --type static --target-path $upload.Target --clean false --restart false --only-show-errors
        if ($LASTEXITCODE -ne 0) {
            throw "Azure upload failed for $($upload.Relative)."
        }
    }
    Write-Host 'Upload calls completed. Run php artisan academy-files:validate in the App Service SSH console before cutover.'
} else {
    Write-Host "No Azure calls were made. Re-run with -Execute -ConfirmTarget '$expectedConfirmation' after reviewing this inventory."
}
