<#
.SYNOPSIS
  CRM video recording automation agent for REYA/Clinicya training videos.

.DESCRIPTION
  Runs the live Chrome/CDP recorder for each CRM training mode, then builds edited
  videos with AI voiceover. Designed to be re-runnable, logged, and easy to audit.

IMPORTANT
  - overview, membership, rewards are read-only navigation recordings.
  - direct adds real points to the selected customer and may send a LINE receipt.
  - qr creates a real one-time claim QR.
  Use -IncludeStateChanging to include direct + qr.

EXAMPLES
  # Validate environment and edit manifest only
  .\docs\training\video-tools\run-crm-video-agent.ps1 -DryRun

  # Record safe read-only clips only, then build edited videos
  .\docs\training\video-tools\run-crm-video-agent.ps1

  # Record all 5 clips, including real direct points + QR actions, then edit
  .\docs\training\video-tools\run-crm-video-agent.ps1 -IncludeStateChanging

  # Record all 5 but skip edit step
  .\docs\training\video-tools\run-crm-video-agent.ps1 -IncludeStateChanging -SkipEdit
#>

[CmdletBinding()]
param(
  [string]$BaseUrl = $(if ($env:CLINICYA_TRAINING_BASE_URL) { $env:CLINICYA_TRAINING_BASE_URL } else { "https://tenant-0001.re-ya.com" }),
  [string]$Username = $env:CLINICYA_TRAINING_USERNAME,
  [string]$Password = $env:CLINICYA_TRAINING_PASSWORD,
  [string]$PointsToAdd = $(if ($env:CLINICYA_POINTS_TO_ADD) { $env:CLINICYA_POINTS_TO_ADD } else { "1" }),
  [ValidateSet("auto", "google", "openai", "edge")]
  [string]$TtsProvider = $(if ($env:CLINICYA_TTS_PROVIDER) { $env:CLINICYA_TTS_PROVIDER } else { "auto" }),
  [switch]$IncludeStateChanging,
  [switch]$SkipRecord,
  [switch]$SkipEdit,
  [switch]$DryRun,
  [switch]$Force
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..\..")
$VideosDir = Join-Path $RepoRoot "docs\training\videos"
$EditedDir = Join-Path $VideosDir "edited"
$RunLogDir = Join-Path $VideosDir "run-logs"
New-Item -ItemType Directory -Force -Path $RunLogDir, $EditedDir | Out-Null

$RunId = Get-Date -Format "yyyyMMdd-HHmmss"
$LogFile = Join-Path $RunLogDir "crm-video-agent-$RunId.log"
$SummaryFile = Join-Path $RunLogDir "crm-video-agent-$RunId.summary.json"

$ModeOutputs = [ordered]@{
  overview   = "crm-overview-click-recording.mp4"
  membership = "crm-membership-points-click-recording.mp4"
  rewards    = "crm-rewards-redemption-click-recording.mp4"
  direct     = "crm-inbox-add-points-click-recording.mp4"
  qr         = "crm-inbox-points-qr-click-recording.mp4"
}

function Write-AgentLog {
  param([string]$Message, [string]$Level = "INFO")
  $line = "[{0}] [{1}] {2}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Level, $Message
  $line | Tee-Object -FilePath $LogFile -Append
}

function Assert-Command {
  param([string]$Name)
  if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
    throw "Required command not found: $Name"
  }
}

function Get-VideoDurationSeconds {
  param([string]$Path)
  $raw = & ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 $Path
  if ($LASTEXITCODE -ne 0) { throw "ffprobe failed for $Path" }
  return [double]::Parse($raw.Trim(), [Globalization.CultureInfo]::InvariantCulture)
}

function Invoke-LoggedProcess {
  param(
    [string]$FilePath,
    [string[]]$Arguments,
    [string]$StepName
  )
  Write-AgentLog "START $StepName :: $FilePath $($Arguments -join ' ')"
  $started = Get-Date
  & $FilePath @Arguments 2>&1 | Tee-Object -FilePath $LogFile -Append
  $exit = $LASTEXITCODE
  $elapsed = [Math]::Round(((Get-Date) - $started).TotalSeconds, 1)
  if ($exit -ne 0) {
    Write-AgentLog "FAILED $StepName exit=$exit elapsed=${elapsed}s" "ERROR"
    throw "$StepName failed with exit code $exit"
  }
  Write-AgentLog "DONE $StepName exit=$exit elapsed=${elapsed}s"
}

function Test-SourceVideos {
  param([string[]]$Modes)
  $result = @()
  foreach ($mode in $Modes) {
    $file = Join-Path $VideosDir $ModeOutputs[$mode]
    $exists = Test-Path $file
    $duration = $null
    if ($exists) {
      try { $duration = [Math]::Round((Get-VideoDurationSeconds $file), 3) } catch { $duration = $null }
    }
    $result += [ordered]@{
      mode = $mode
      path = $file
      exists = $exists
      duration_seconds = $duration
    }
  }
  return $result
}

try {
  Write-AgentLog "CRM video agent started run_id=$RunId repo=$RepoRoot"
  Push-Location $RepoRoot

  Assert-Command node
  Assert-Command python
  Assert-Command ffmpeg
  Assert-Command ffprobe

  if (-not $Username -or -not $Password) {
    throw "Set CLINICYA_TRAINING_USERNAME and CLINICYA_TRAINING_PASSWORD, or pass -Username / -Password."
  }

  $env:CLINICYA_TRAINING_BASE_URL = $BaseUrl
  $env:CLINICYA_TRAINING_USERNAME = $Username
  $env:CLINICYA_TRAINING_PASSWORD = $Password
  $env:CLINICYA_POINTS_TO_ADD = $PointsToAdd
  $env:CLINICYA_TTS_PROVIDER = $TtsProvider

  $modes = @("overview", "membership", "rewards")
  if ($IncludeStateChanging) {
    $modes += @("direct", "qr")
    Write-AgentLog "State-changing modes ENABLED: direct adds real points; qr creates a real claim QR." "WARN"
  } else {
    Write-AgentLog "State-changing modes skipped. Add -IncludeStateChanging to record direct + qr." "WARN"
  }

  if ($DryRun) {
    Write-AgentLog "DryRun: validating edit config only; no recorder or TTS media generation."
    Invoke-LoggedProcess -FilePath "python" -Arguments @("docs\training\video-tools\edit-voiceover-videos.py", "--dry-run") -StepName "edit dry-run"
    $sourceStatus = Test-SourceVideos -Modes @("overview", "membership", "rewards", "direct", "qr")
    $summary = [ordered]@{
      run_id = $RunId
      dry_run = $true
      base_url = $BaseUrl
      include_state_changing = [bool]$IncludeStateChanging
      log = $LogFile
      source_videos = $sourceStatus
    }
    $summary | ConvertTo-Json -Depth 8 | Set-Content -Encoding UTF8 $SummaryFile
    Write-AgentLog "DryRun summary written: $SummaryFile"
    exit 0
  }

  if (-not $SkipRecord) {
    foreach ($mode in $modes) {
      $outFile = Join-Path $VideosDir $ModeOutputs[$mode]
      if ((Test-Path $outFile) -and -not $Force) {
        Write-AgentLog "SKIP record mode=$mode because output exists: $outFile. Use -Force to overwrite."
        continue
      }
      $env:CLINICYA_POINTS_MODE = $mode
      Invoke-LoggedProcess -FilePath "node" -Arguments @("docs\training\video-tools\record-inbox-points-action.js") -StepName "record mode=$mode"
      if (-not (Test-Path $outFile)) { throw "Expected recording was not created: $outFile" }
      $duration = [Math]::Round((Get-VideoDurationSeconds $outFile), 3)
      if ($duration -lt 3) { throw "Recording too short for mode=$mode duration=$duration seconds" }
      Write-AgentLog "Verified recording mode=$mode duration=${duration}s path=$outFile"
    }
  } else {
    Write-AgentLog "SkipRecord enabled: not recording source videos."
  }

  Invoke-LoggedProcess -FilePath "python" -Arguments @("docs\training\video-tools\edit-voiceover-videos.py", "--dry-run") -StepName "edit dry-run"

  if (-not $SkipEdit) {
    Invoke-LoggedProcess -FilePath "python" -Arguments @("docs\training\video-tools\edit-voiceover-videos.py") -StepName "build edited voiceover videos"
  } else {
    Write-AgentLog "SkipEdit enabled: not building edited voiceover videos."
  }

  $allSourceStatus = Test-SourceVideos -Modes @("overview", "membership", "rewards", "direct", "qr")
  $editedFiles = Get-ChildItem $EditedDir -Filter "*-edited-voiceover.mp4" -ErrorAction SilentlyContinue | ForEach-Object {
    $duration = $null
    try { $duration = [Math]::Round((Get-VideoDurationSeconds $_.FullName), 3) } catch { $duration = $null }
    [ordered]@{
      path = $_.FullName
      size_mb = [Math]::Round($_.Length / 1MB, 2)
      duration_seconds = $duration
    }
  }

  $summary = [ordered]@{
    run_id = $RunId
    dry_run = $false
    base_url = $BaseUrl
    include_state_changing = [bool]$IncludeStateChanging
    modes_requested = $modes
    tts_provider = $TtsProvider
    log = $LogFile
    summary = $SummaryFile
    source_videos = $allSourceStatus
    edited_videos = $editedFiles
    manifest = Join-Path $EditedDir "edited-voiceover-manifest.json"
  }
  $summary | ConvertTo-Json -Depth 8 | Set-Content -Encoding UTF8 $SummaryFile
  Write-AgentLog "SUCCESS summary written: $SummaryFile"
}
catch {
  Write-AgentLog $_.Exception.Message "ERROR"
  $failedSummary = [ordered]@{
    run_id = $RunId
    failed = $true
    error = $_.Exception.Message
    log = $LogFile
  }
  $failedSummary | ConvertTo-Json -Depth 8 | Set-Content -Encoding UTF8 $SummaryFile
  throw
}
finally {
  Pop-Location -ErrorAction SilentlyContinue
}
