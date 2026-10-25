#!/bin/sh
# Installs the ai_agent plugin into Roundcube without Composer.
#   ./install.sh <Roundcube directory> [--web-group www-data]
# Copies the plugin to <roundcube>/plugins/ai_agent. Keeps an existing
# config.inc.php, creates it from config.inc.php.dist the first time.
# Then enable the plugin in config/config.inc.php: $config['plugins'][] = 'ai_agent';
set -eu

RC=${1:-}
GROUP=www-data
[ "${2:-}" = "--web-group" ] && GROUP=${3:?missing group}
[ -n "$RC" ] && [ -f "$RC/program/include/iniset.php" ] || { echo "Usage: $0 <Roundcube directory> [--web-group GROUP]" >&2; exit 1; }

SRC=$(cd "$(dirname "$0")" && pwd)
DST=$RC/plugins/ai_agent

mkdir -p "$DST"
for f in ai_agent.php ai_agent.js mail.js settings.js composer.json config.inc.php.dist; do
    install -m 0644 "$SRC/$f" "$DST/$f"
done
for d in localization skins js; do
    rm -rf "${DST:?}/$d"
    cp -r "$SRC/$d" "$DST/$d"
done
chown -R root:root "$DST"
find "$DST" -type d -exec chmod 0755 {} + && find "$DST" -type f -exec chmod 0644 {} +

if [ ! -f "$DST/config.inc.php" ]; then
    cp "$DST/config.inc.php.dist" "$DST/config.inc.php"
    echo "Created $DST/config.inc.php — set ai_agent_api_url."
fi
chown root:"$GROUP" "$DST/config.inc.php" && chmod 0640 "$DST/config.inc.php"

grep -q "ai_agent" "$RC/config/config.inc.php" \
    || echo "The plugin is not enabled yet: add 'ai_agent' to \$config['plugins'] in $RC/config/config.inc.php"
echo "Done: $DST"
