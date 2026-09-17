#!/bin/sh
set -eu
cd "$(dirname "$0")/.."

wp() {
	npx --yes @wordpress/env@11.15.0 run cli wp "$@" 2>/dev/null
}

wp rewrite structure '/%postname%/' --quiet

if ! wp user get agent --field=ID >/dev/null 2>&1; then
	wp user create agent agent@example.com --role=editor --display_name='Agent' --quiet >/dev/null
fi

post_id=$(wp post list --post_type=post --title='Summer Sale' --post_status=publish --field=ID --posts_per_page=1 | tail -n 1)
if [ -z "$post_id" ]; then
	post_id=$(wp post create --post_title='Summer Sale' --post_status=publish --porcelain | tail -n 1)
fi

for uuid in $(wp user application-password list agent --field=uuid 2>/dev/null); do
	wp user application-password delete agent "$uuid" --quiet
done

mkdir -p .demo
password=$(wp user application-password create agent "demo $(date +%Y%m%d%H%M%S)" --porcelain | tail -n 1)
token=$(printf 'agent:%s' "$password" | base64 | tr -d '\n')
url="http://localhost:8881/wp-json/mcp/mcp-adapter-default-server"

cat > .demo/connect-claude-code.sh <<SCRIPT
#!/bin/sh
claude mcp add --transport http agent-review "$url" --header "Authorization: Basic $token"
SCRIPT
chmod +x .demo/connect-claude-code.sh

echo "Demo ready: post #$post_id 'Summer Sale', Editor user 'agent'."
echo "Connect Claude Code with: .demo/connect-claude-code.sh"
