<#
.SYNOPSIS
  Tải file cài đặt Android (.apk) mới nhất do GitHub Actions (workflow "Android CI") build về máy local.

.EXAMPLE
  .\scripts\download-apk.ps1                           # bản mới nhất trên main
  .\scripts\download-apk.ps1 -Branch feat/android-kiosk-app
  .\scripts\download-apk.ps1 -Build                    # kích hoạt build mới trên main, chờ xong rồi tải
#>
param(
    [string]$Branch = "main",
    [string]$RunId,
    [string]$OutDir = "$PSScriptRoot\..\dist\android",
    [switch]$Build
)
$ErrorActionPreference = "Stop"
$Repo = "nobita6986/tad-attend"
$Workflow = "android-ci.yml"

if ($Build) {
    gh workflow run $Workflow -R $Repo --ref $Branch
    Start-Sleep -Seconds 10
    $RunId = gh run list -R $Repo -w $Workflow -b $Branch -L 1 --json databaseId -q ".[0].databaseId"
    Write-Host "Đang chờ build run $RunId ..."
    gh run watch $RunId -R $Repo --exit-status | Out-Null
}

if (-not $RunId) {
    $RunId = gh run list -R $Repo -w $Workflow -b $Branch -s success -L 1 --json databaseId -q ".[0].databaseId"
    if (-not $RunId) { throw "Không tìm thấy lần build Android thành công nào trên nhánh '$Branch'." }
}

New-Item -ItemType Directory -Force $OutDir | Out-Null
$Target = Join-Path $OutDir $RunId
if (Test-Path $Target) { Remove-Item -Recurse -Force $Target }
gh run download $RunId -R $Repo -p "android-apk-*" -D $Target
Get-ChildItem $Target -Recurse -Filter *.apk | ForEach-Object {
    Write-Host ("{0}  ({1:N1} MB)" -f $_.FullName, ($_.Length / 1MB))
}
