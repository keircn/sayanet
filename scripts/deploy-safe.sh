#!/usr/bin/env bash
# deploy-safe: deploy build/_sayanet to DEST without overwriting user configs
# Usage: ./scripts/deploy-safe.sh /usr/share/nginx/html
#    or: DEST=/usr/share/nginx/html pnpm run deploy:safe
#    or: pnpm run deploy:safe -- --dest=/usr/share/nginx/html
set -euo pipefail

DEST="${1:-${DEST:-}}"
# also support --dest= form
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

# allow DEST to be the _sayanet dir itself or its parent
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

# files/dirs to never overwrite if they already exist in DEST
# - private/conf/options.json  (main user config)
# - private/conf/types.json    (rarely customized, but preserve if exists)
# - private/cache/**            (thumbnails, don't wipe)
# - anything in private/conf/l10n that user edited? we preserve only options.json for now, but also keep types.json
PRESERVE=(
  "private/conf/options.json"
  "private/conf/types.json"
)

echo "Deploying $BUILD -> $DEST_SAYANET (preserving configs)"

if command -v rsync >/dev/null 2>&1; then
  # rsync with excludes for preserved files + cache + ideal
  rsync -av --no-perms --no-owner --no-group \
    --exclude='private/conf/options.json' \
    --exclude='private/conf/types.json' \
    --exclude='private/conf/options.ideal.json' \
    --exclude='private/cache/**' \
    --exclude='private/cache' \
    "$BUILD/" "$DEST_SAYANET/"

  # handle preserved files: only install if missing, else save as .new
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
      # save new default as .new for diff review
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

  # also handle options.ideal.json if it exists in build
  if [ -f "$BUILD/private/conf/options.ideal.json" ]; then
    if [ ! -f "$DEST_SAYANET/private/conf/options.ideal.json" ]; then
      cp -a "$BUILD/private/conf/options.ideal.json" "$DEST_SAYANET/private/conf/options.ideal.json"
      echo "  installed options.ideal.json"
    else
      cp -a "$BUILD/private/conf/options.ideal.json" "$DEST_SAYANET/private/conf/options.ideal.json.new"
      echo "  preserved options.ideal.json (new as .new)"
    fi
  fi

else
  echo "rsync not found, falling back to cp (slower, less safe)" >&2
  # fallback: cp -rn (no-clobber) for preserved files is tricky, do manual
  # copy all except preserved
  # use tar pipeline to exclude
  if command -v tar >/dev/null 2>&1; then
    (cd "$BUILD" && tar cf - --exclude='private/conf/options.json' --exclude='private/conf/types.json' --exclude='private/cache' .) | (cd "$DEST_SAYANET" && tar xpf -)
  else
    # last resort: cp -a and then restore preserved files from backup
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

# ensure cache dir exists and is writable
mkdir -p "$DEST_SAYANET/private/cache" "$DEST_SAYANET/public/cache"
chmod 755 "$DEST_SAYANET/private/cache" "$DEST_SAYANET/public/cache" 2>/dev/null || true

# set permissions for web server (try www-data)
if id www-data >/dev/null 2>&1; then
  chown -R www-data:www-data "$DEST_SAYANET/private/cache" "$DEST_SAYANET/public/cache" 2>/dev/null || true
fi

echo "Deploy done. Preserved: ${PRESERVE[*]} (+ cache). Review *.new files for upstream changes."
echo "Tip: compare with: diff -u $DEST_SAYANET/private/conf/options.json $DEST_SAYANET/private/conf/options.json.new"
