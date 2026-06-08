<#
.SYNOPSIS
  V2 CRM manual recording agent: records the full 16-episode CRM manual set.

.DESCRIPTION
  This does NOT use the old 5-clip points-only plan as the final product.
  It runs record-crm-manual-v2.js episode-by-episode, writes logs, verifies
  video duration with ffprobe, and produces a summary manifest.

  Default behavior is conservative:
  - records all 16 CRM manual episodes as walkthrough/navigation clips
  - does not intentionally click final destructive/confirm actions
  - skips existing files unless -Force is provided

.EXAMPLES
  .\docs\training\video-tools\run-crm-manual-v2-agent.ps1 -DryRun
  .\docs\training\video-tools\run-crm-manual-v2-agent.ps1
  .\docs\training\video-tools\run-crm-manual-v2-agent.ps1 -Episodes 01,02,03 -Force
#>

[CmdletBinding()]
param(
  [string]$BaseUrl = $(if ($env:CLINICYA_TRAINING_BASE_URL) { $env:CLINICYA_TRAINING_BASE_URL } else { "https://tenant-0001.re-ya.com" }),
  [string]$Username = $env:CLINICYA_TRAINING_USERNAME,
  [string]$Password = $env:CLINICYA_TRAINING_PASSWORD,
  [string[]]$Episodes = @("01","02","03","04","05","06","07","08","09","10","11","12","13","14","15","16"),
  [double]$MinimumDurationSeconds = 20,
  [switch]$DryRun,
  [switch]$Force
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..\..")
$OutDir = Join-Path $RepoRoot "docs\training\videos\crm-manual-v2"
$LogDir = Join-Path $OutDir "run-logs"
New-Item -ItemType Directory -Force -Path $OutDir, $LogDir | Out-Null

$RunId = Get-Date -Format "yyyyMMdd-HHmmss"
$LogFile = Join-Path $LogDir "crm-manual-v2-agent-$RunId.log"
$SummaryFile = Join-Path $LogDir "crm-manual-v2-agent-$RunId.summary.json"

$EpisodeFiles = [ordered]@{
  "01" = "crm-01-overview-team-roles.mp4"
  "02" = "crm-02-dashboard-crm.mp4"
  "03" = "crm-03-inbox-3-column.mp4"
  "04" = "crm-04-inbox-search-filter-queue.mp4"
  "05" = "crm-05-chat-quick-reply-transfer.mp4"
  "06" = "crm-06-crm-hud-before-reply.mp4"
  "07" = "crm-07-tags-and-notes.mp4"
  "08" = "crm-08-orders-medicine-history.mp4"
  "09" = "crm-09-dispense-line-label.mp4"
  "10" = "crm-10-refill-follow-up.mp4"
  "11" = "crm-11-customers-management.mp4"
  "12" = "crm-12-sales-pipeline-deals.mp4"
  "13" = "crm-13-service-center-tickets.mp4"
  "14" = "crm-14-tag-segment-broadcast.mp4"
  "15" = "crm-15-crm-analytics.mp4"
  "16" = "crm-16-crm-weekly-reports.mp4"
}

function Write-AgentLog {
  param([string]$Message, [string]$Level = "INFO")
  $line = "[{0}] [{1}] {2}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Level, $Message
  $line | Tee-Object -FilePath $LogFile -Append
}

function Assert-Command {
  param([string]$Name)
  if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) { throw "Required command not found: $Name" }
}

function Get-VideoDurationSeconds {
  param([string]$Path)
  $raw = & ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 $Path
  if ($LASTEXITCODE -ne 0) { throw "ffprobe failed for $Path" }
  return [double]::Parse($raw.Trim(), [Globalization.CultureInfo]::InvariantCulture)
}

function Invoke-LoggedProcess {
  param([string]$FilePath, [string[]]$Arguments, [string]$StepName)
  Write-AgentLog "START $StepName :: $FilePath $($Arguments -join ' ')"
  $started = Get-Date
  $oldErrorActionPreference = $ErrorActionPreference
  try {
    # Native stderr should be logged, but must not abort the wrapper before we can
    # inspect $LASTEXITCODE. The recorder may emit transient Chrome/CDP warnings.
    $ErrorActionPreference = "Continue"
    & $FilePath @Arguments 2>&1 | ForEach-Object { "$($_)" | Tee-Object -FilePath $LogFile -Append }
    $exit = $LASTEXITCODE
  }
  finally {
    $ErrorActionPreference = $oldErrorActionPreference
  }
  $elapsed = [Math]::Round(((Get-Date) - $started).TotalSeconds, 1)
  if ($exit -ne 0) {
    Write-AgentLog "FAILED $StepName exit=$exit elapsed=${elapsed}s" "ERROR"
    throw "$StepName failed with exit code $exit"
  }
  Write-AgentLog "DONE $StepName exit=$exit elapsed=${elapsed}s"
}

function Get-Status {
  param([string[]]$EpisodeIds)
  $items = @()
  foreach ($id in $EpisodeIds) {
    if (-not $EpisodeFiles.Contains($id)) { throw "Unknown episode id: $id" }
    $file = Join-Path $OutDir $EpisodeFiles[$id]
    $exists = Test-Path $file
    $duration = $null
    $sizeMb = $null
    if ($exists) {
      $f = Get-Item $file
      $sizeMb = [Math]::Round($f.Length / 1MB, 2)
      try { $duration = [Math]::Round((Get-VideoDurationSeconds $file), 3) } catch { $duration = $null }
    }
    $items += [ordered]@{ episode=$id; path=$file; exists=$exists; size_mb=$sizeMb; duration_seconds=$duration }
  }
  return $items
}

try {
  Write-AgentLog "CRM manual v2 agent started run_id=$RunId repo=$RepoRoot"
  Push-Location $RepoRoot

  Assert-Command node
  Assert-Command ffmpeg
  Assert-Command ffprobe

  $Episodes = @($Episodes | ForEach-Object { ([string]$_).PadLeft(2, '0') })
  foreach ($ep in $Episodes) {
    if (-not $EpisodeFiles.Contains($ep)) { throw "Unknown episode id: $ep" }
  }

  $env:CLINICYA_TRAINING_BASE_URL = $BaseUrl
  if ($Username) { $env:CLINICYA_TRAINING_USERNAME = $Username }
  if ($Password) { $env:CLINICYA_TRAINING_PASSWORD = $Password }

  if ($DryRun) {
    Invoke-LoggedProcess -FilePath "node" -Arguments @("docs\training\video-tools\record-crm-manual-v2.js", "--dry-run") -StepName "manual v2 dry-run"
    $summary = [ordered]@{
      run_id = $RunId
      dry_run = $true
      base_url = $BaseUrl
      episodes_requested = $Episodes
      log = $LogFile
      status = Get-Status -EpisodeIds $Episodes
      plan = Join-Path $OutDir "crm-manual-v2-plan.json"
    }
    $summary | ConvertTo-Json -Depth 8 | Set-Content -Encoding UTF8 $SummaryFile
    Write-AgentLog "DryRun summary written: $SummaryFile"
    exit 0
  }

  if (-not $env:CLINICYA_TRAINING_USERNAME -or -not $env:CLINICYA_TRAINING_PASSWORD) {
    throw "Set CLINICYA_TRAINING_USERNAME and CLINICYA_TRAINING_PASSWORD, or pass -Username / -Password."
  }

  foreach ($ep in $Episodes) {
    $out = Join-Path $OutDir $EpisodeFiles[$ep]
    if ((Test-Path $out) -and -not $Force) {
      Write-AgentLog "SKIP episode=$ep because output exists: $out. Use -Force to overwrite."
      continue
    }
    $env:CLINICYA_CRM_EPISODE = $ep
    Invoke-LoggedProcess -FilePath "node" -Arguments @("docs\training\video-tools\record-crm-manual-v2.js") -StepName "record manual v2 episode=$ep"
    if (-not (Test-Path $out)) { throw "Expected recording was not created: $out" }
    $duration = [Math]::Round((Get-VideoDurationSeconds $out), 3)
    if ($duration -lt $MinimumDurationSeconds) {
      throw "Episode $ep recording too short: ${duration}s < ${MinimumDurationSeconds}s"
    }
    Write-AgentLog "Verified episode=$ep duration=${duration}s path=$out"
  }

  $status = Get-Status -EpisodeIds $Episodes
  $manifest = [ordered]@{
    generated_at = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
    run_id = $RunId
    base_url = $BaseUrl
    ai_voice_disclosure = "AI voiceover disclosure included in recorder overlay"
    privacy_note = "Live tenant recording; internal use only."
    videos = $status
  }
  $manifestPath = Join-Path $OutDir "crm-manual-v2-manifest.json"
  $manifest | ConvertTo-Json -Depth 8 | Set-Content -Encoding UTF8 $manifestPath

  $summary = [ordered]@{
    run_id = $RunId
    dry_run = $false
    base_url = $BaseUrl
    episodes_requested = $Episodes
    log = $LogFile
    summary = $SummaryFile
    manifest = $manifestPath
    status = $status
  }
  $summary | ConvertTo-Json -Depth 8 | Set-Content -Encoding UTF8 $SummaryFile
  Write-AgentLog "SUCCESS summary written: $SummaryFile"
}
catch {
  Write-AgentLog $_.Exception.Message "ERROR"
  $failedSummary = [ordered]@{ run_id=$RunId; failed=$true; error=$_.Exception.Message; log=$LogFile }
  $failedSummary | ConvertTo-Json -Depth 8 | Set-Content -Encoding UTF8 $SummaryFile
  throw
}
finally {
  Pop-Location -ErrorAction SilentlyContinue
}
