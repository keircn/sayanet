#!/usr/bin/env bash
set -euo pipefail

DEST="${1:-${DEST:-}}"
for arg in "$@"; do
  case "$arg" in
    --dest=*|--DEST=*) DEST="${arg#*=}" ;;
    --help|-h)
      echo "Usage: $0 <dest>  (e.g. $0 /usr/share/nginx/html)"
      echo "Env: DEST=<path>  pnpm run deploy:safe"
      exit 0
      ;;
  esac
done

if [ -z "$DEST" ]; then
  echo "error: no destination. Usage: $0 <dest>  or DEST=/path $0" >&2
  exit 1
fi

if [[ "$DEST" == *"/_sayanet" ]]; then
  DEST_ROOT="$(dirname "$DEST")"
  DEST_SAYANET="$DEST"
else
  DEST_ROOT="$DEST"
  DEST_SAYANET="$DEST/_sayanet"
fi

BUILD="build/_sayanet"
if [ ! -d "$BUILD" ]; then
  echo "build/_sayanet not found, running pnpm run build..." >&2
  pnpm run build
fi

if [ ! -d "$BUILD" ]; then
  echo "error: $BUILD still missing after build" >&2
  exit 1
fi

mkdir -p "$DEST_SAYANET"

PRESERVE=(
  "private/conf/options.json"
  "private/conf/types.json"
)

echo "Deploying $BUILD -> $DEST_SAYANET (preserving configs)"

if command -v rsync >/dev/null 2>&1; then
  rsync -av --no-perms --no-owner --no-group \
    --exclude='private/conf/options.json' \
    --exclude='private/conf/types.json' \
    --exclude='private/conf/options.example.json' \
    --exclude='private/cache/**' \
    --exclude='private/cache' \
    "$BUILD/" "$DEST_SAYANET/"

  for rel in "${PRESERVE[@]}"; do
    src="$BUILD/$rel"
    dst="$DEST_SAYANET/$rel"
    if [ ! -f "$dst" ]; then
      if [ -f "$src" ]; then
        mkdir -p "$(dirname "$dst")"
        cp -a "$src" "$dst"
        echo "  installed $rel (was missing)"
      fi
    else
      if [ -f "$src" ]; then
        cp -a "$src" "$dst.new"
        echo "  preserved $rel (new version saved as $rel.new)"
        if command -v diff >/dev/null 2>&1; then
          if ! diff -q "$dst" "$src" >/dev/null 2>&1; then
            echo "    diff: $dst vs $src differ (review $rel.new)"
          fi
        fi
      fi
    fi
  done

  if [ -f "$BUILD/private/conf/options.example.json" ]; then
    if [ ! -f "$DEST_SAYANET/private/conf/options.example.json" ]; then
      cp -a "$BUILD/private/conf/options.example.json" "$DEST_SAYANET/private/conf/options.example.json"
      echo "  installed options.example.json"
    else
      cp -a "$BUILD/private/conf/options.example.json" "$DEST_SAYANET/private/conf/options.example.json.new"
      echo "  preserved options.example.json (new as .new)"
    fi
  fi

else
  echo "rsync not found, falling back to cp (slower, less safe)" >&2
  if command -v tar >/dev/null 2>&1; then
    (cd "$BUILD" && tar cf - --exclude='private/conf/options.json' --exclude='private/conf/types.json' --exclude='private/cache' .) | (cd "$DEST_SAYANET" && tar xpf -)
  else
    for rel in "${PRESERVE[@]}"; do
      if [ -f "$DEST_SAYANET/$rel" ]; then
        cp -a "$DEST_SAYANET/$rel" "/tmp/sayanet-preserve-$(basename "$rel")"
      fi
    done
    cp -a "$BUILD/." "$DEST_SAYANET/"
    for rel in "${PRESERVE[@]}"; do
      tmp="/tmp/sayanet-preserve-$(basename "$rel")"
      if [ -f "$tmp" ]; then
        cp -a "$tmp" "$DEST_SAYANET/$rel"
        cp -a "$BUILD/$rel" "$DEST_SAYANET/$rel.new" 2>/dev/null || true
        echo "  preserved $rel"
        rm -f "$tmp"
      fi
    done
  fi
fi

mkdir -p "$DEST_SAYANET/private/cache" "$DEST_SAYANET/public/cache"
chmod 777 "$DEST_SAYANET/private/cache" "$DEST_SAYANET/public/cache" 2>/dev/null || chmod 775 "$DEST_SAYANET/private/cache" "$DEST_SAYANET/public/cache" 2>/dev/null || true
for u in www-data nginx http php-fpm apache www; do
  if id "$u" >/dev/null 2>&1; then
    chown -R "$u:$u" "$DEST_SAYANET/private/cache" "$DEST_SAYANET/public/cache" 2>/dev/null || true
    chmod 775 "$DEST_SAYANET/private/cache" "$DEST_SAYANET/public/cache" 2>/dev/null || true
    break
  fi
done
if [ -f /usr/share/nginx/html/_sayanet/private/cache/.test_writable ]; then rm -f /usr/share/nginx/html/_sayanet/private/cache/.test_writable; fi
touch "$DEST_SAYANET/private/cache/.test_writable" 2>/dev/null && rm -f "$DEST_SAYANET/private/cache/.test_writable" || chmod 777 "$DEST_SAYANET/private/cache" 2>/dev/null || true
touch "$DEST_SAYANET/public/cache/.test_writable" 2>/dev/null && rm -f "$DEST_SAYANET/public/cache/.test_writable" || chmod 777 "$DEST_SAYANET/public/cache" 2>/dev/null || true

echo "Deploy done. Preserved: ${PRESERVE[*]} (+ cache). Review *.new files for upstream changes."
echo "Tip: compare with: diff -u $DEST_SAYANET/private/conf/options.json $DEST_SAYANET/private/conf/options.json.new"
