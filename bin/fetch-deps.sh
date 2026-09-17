#!/bin/sh
set -eu

AGENTS_API_COMMIT=1c07ed47fa35f816f907adce02e0fc4cffdf0c63
MCP_ADAPTER_VERSION=0.6.1
MCP_ADAPTER_SHA256=1c3cd47c32e99b4e7d8690a44a7890256e92a8b96f61776cbe1894e5483cf676

root=$(cd "$(dirname "$0")/.." && pwd)
deps="$root/.deps"
mkdir -p "$deps"

if [ "$(git -C "$deps/agents-api" rev-parse HEAD 2>/dev/null || true)" != "$AGENTS_API_COMMIT" ]; then
	rm -rf "$deps/agents-api"
	git init -q "$deps/agents-api"
	git -C "$deps/agents-api" fetch -q --depth 1 https://github.com/Automattic/agents-api.git "$AGENTS_API_COMMIT"
	git -C "$deps/agents-api" checkout -q FETCH_HEAD
fi

if [ "$(cat "$deps/mcp-adapter.sha256" 2>/dev/null || true)" != "$MCP_ADAPTER_SHA256" ] || [ ! -f "$deps/mcp-adapter/includes/Core/McpAdapter.php" ]; then
	tmp=$(mktemp -d)
	trap 'rm -rf "$tmp"' EXIT
	curl -fsSL -o "$tmp/mcp-adapter.zip" "https://github.com/WordPress/mcp-adapter/releases/download/v$MCP_ADAPTER_VERSION/mcp-adapter.zip"
	actual=$(shasum -a 256 "$tmp/mcp-adapter.zip" 2>/dev/null || sha256sum "$tmp/mcp-adapter.zip")
	if [ "${actual%% *}" != "$MCP_ADAPTER_SHA256" ]; then
		echo "mcp-adapter.zip checksum mismatch: ${actual%% *}" >&2
		exit 1
	fi
	unzip -q "$tmp/mcp-adapter.zip" -d "$tmp/unzipped"
	rm -rf "$deps/mcp-adapter" "$deps/mcp-adapter.sha256"
	mv "$tmp/unzipped/mcp-adapter" "$deps/mcp-adapter"
	echo "$MCP_ADAPTER_SHA256" > "$deps/mcp-adapter.sha256"
fi

echo "agents-api $(git -C "$deps/agents-api" rev-parse --short HEAD), mcp-adapter $(sed -n 's/^ \* Version: *//p' "$deps/mcp-adapter/mcp-adapter.php")"
