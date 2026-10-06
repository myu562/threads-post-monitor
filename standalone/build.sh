#!/usr/bin/env bash
# Builds self-contained single-file executables for Windows, macOS (Apple Silicon and Intel) and Linux.
# Output: dist/<rid>/ThreadsMonitor (dist/win-x64/ThreadsMonitor.exe on Windows)
# Release builds carry no PDB and map source paths to /_/; check-binaries.sh verifies it at the end.
set -euo pipefail

cd "$(dirname "$0")"
DOTNET="${DOTNET:-dotnet}"
export DOTNET_CLI_TELEMETRY_OPTOUT=1 DOTNET_NOLOGO=1

for rid in win-x64 osx-arm64 osx-x64 linux-x64; do
  echo "== $rid"
  "$DOTNET" publish src/ThreadsMonitor/ThreadsMonitor.csproj \
    -c Release \
    -r "$rid" \
    --self-contained \
    -p:PublishSingleFile=true \
    -p:InvariantGlobalization=false \
    -p:EnableCompressionInSingleFile=true \
    -o "obj/publish/$rid"
  mkdir -p "dist/$rid"
  if [ "$rid" = "win-x64" ]; then exe=ThreadsMonitor.exe; else exe=ThreadsMonitor; fi
  cp "obj/publish/$rid/$exe" "dist/$rid/$exe"
done

echo
ls -l dist/*/

# Fail the build when any binary still contains a path of this machine (see scripts/check-binaries.sh).
echo
bash scripts/check-binaries.sh
