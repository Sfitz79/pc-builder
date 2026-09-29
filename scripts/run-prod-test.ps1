<#
    Run a Neon-gated production test against PRODUCTION.

    Usage:
        powershell -ExecutionPolicy Bypass -File scripts\run-prod-test.ps1 -Filter CaseFormFactorGateTest
        powershell -ExecutionPolicy Bypass -File scripts\run-prod-test.ps1 -Filter ProductionPriceEvidenceTest -GateEnv PRICE_EVIDENCE_REGRESSION

    WHY THIS EXISTS
    The production-regression tests are gated on an env var and, more
    importantly, they are worthless unless they are actually pointed at Neon.
    phpunit.xml.dist pins DB_CONNECTION=sqlite, so running them plainly
    silently tests a local file and then the genie-prod-guard aborts with
    exit 3 - correct, but only after a confusing amount of work.

    This script loads .env.production.neon into the process environment first,
    then runs the requested filter. The guard still runs inside the test and
    will abort if the driver is not pgsql, so this is a convenience, never a
    substitute for the guard.

    The .neon file holds the owner-held Neon credentials. It is gitignored and
    this script never echoes a value - only the key names it loaded.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $Filter,

    # Name of the gate env var the test checks (default: FORM_FACTOR_REGRESSION).
    [string] $GateEnv = 'FORM_FACTOR_REGRESSION',

    # Extra test arguments after --filter, e.g. '--testsuite=Feature'.
    [string[]] $TestArgs = @()
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root '.env.production.neon'

if (-not (Test-Path -LiteralPath $envFile)) {
    Write-Error "Production env file not found: $envFile"
    exit 2
}

# Load KEY=VALUE lines into this process only. Values are never printed.
$loaded = 0
foreach ($line in Get-Content -LiteralPath $envFile) {
    $trimmed = $trimmed = $line.Trim()
    if ($trimmed -eq '' -or $trimmed.StartsWith('#')) { continue }
    $eq = $trimmed.IndexOf('=')
    if ($eq -lt 1) { continue }
    $key = $trimmed.Substring(0, $eq).Trim()
    $value = $trimmed.Substring($eq + 1).Trim().Trim('"')
    Set-Item -Path "env:$key" -Value $value
    $loaded++
}

Write-Host "Loaded $loaded production env keys from .env.production.neon (values hidden)."

# The DIRECT Neon host is used, not the pooled one.
#
# The pooled endpoint (ep-*.pooler.*) refuses connections from this PHP/libpq
# build unless the endpoint id is passed as a connection option:
#   "Endpoint ID is not specified ... pass the endpoint ID ... as '?options=endpoint%3D<id>'"
# The direct host has no pooler in front of it and works. The previous version
# of this script preferred DB_POOLED_HOST, which is correct for the DEPLOYED
# app but made every production test fail to connect at all.
$env:DB_CONNECTION = 'pgsql'

# Open the gate so the gated test does not skip itself.
Set-Item -Path "env:$GateEnv" -Value 'neon'

Push-Location $root
try {
    $arguments = @('artisan', 'test', "--filter=$Filter")
    if ($TestArgs.Count -gt 0) { $arguments += $TestArgs }

    & php @arguments
    $code = $LASTEXITCODE
}
finally {
    Pop-Location
}

# 3 is genie-prod-guard's "wrong database" code; surface it distinctly, because
# it means the test did NOT read production and the result must not be trusted.
if ($code -eq 3) {
    Write-Error "genie-prod-guard aborted: the test did NOT run against production. Treat any output above as invalid."
}

exit $code
