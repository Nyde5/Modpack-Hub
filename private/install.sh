#!/bin/bash

set -euo pipefail

PACK_URL="${PACK_URL:-}"
PACK_FORMAT="${PACK_FORMAT:-zip}"
MC_VERSION="${MC_VERSION:-}"
LOADER="${LOADER:-none}"
LOADER_VERSION="${LOADER_VERSION:-}"
INSTALL_LOADER="${INSTALL_LOADER:-0}"
ACCEPT_EULA="${ACCEPT_EULA:-0}"
CLEAN_MODS="${CLEAN_MODS:-1}"

SERVER_DIR=/mnt/server
TMP="$SERVER_DIR/.modpackhub-tmp"
RESULT="$SERVER_DIR/.modpackhub-result"

FILES_LIST="$SERVER_DIR/.modpackhub-files"

mkdir -p "$SERVER_DIR"
rm -f "$RESULT"

RESULT_WRITTEN=0
result_fail() { RESULT_WRITTEN=1; printf 'fail: %s\n' "$*" > "$RESULT" 2>/dev/null || true; }

on_exit() {
  local code=$?
  if [ "$code" -ne 0 ] && [ "$RESULT_WRITTEN" -eq 0 ]; then
    result_fail "the install script exited with code $code"
  fi
  exit "$code"
}
trap on_exit EXIT

fail() { echo "ModpackHub: $*" >&2; result_fail "$*"; exit 1; }

need() {
  command -v "$1" >/dev/null 2>&1 && return 0
  if command -v apt-get >/dev/null 2>&1; then
    echo "ModpackHub: installing '$2' in the install container..."
    apt-get update -qq >/dev/null 2>&1 || true
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends "$2" >/dev/null 2>&1 || true
  fi
  command -v "$1" >/dev/null 2>&1 || fail "the install image does not have '$1' and I could not install it"
}

CF_HOSTS="edge.forgecdn.net mediafilez.forgecdn.net cdn.modrinth.com"

MR_HOSTS="cdn.modrinth.com github.com raw.githubusercontent.com gitlab.com objects.githubusercontent.com"

CURL_SAFE=(--proto '=https' --proto-redir '=https' --max-redirs 3 -fSL --retry 3 --retry-delay 2)

url_allowed() {
  local url="$1" allow="$2" rest host a
  case "$url" in https://*) rest="${url#https://}" ;; *) return 1 ;; esac
  rest="${rest%%/*}"; rest="${rest%%\?*}"; rest="${rest%%#*}"
  case "$rest" in *@*) return 1 ;; esac
  case "$rest" in
    *:443) host="${rest%:443}" ;;
    *:*)   return 1 ;;
    *)     host="$rest" ;;
  esac
  host="$(printf '%s' "$host" | tr 'A-Z' 'a-z')"
  for a in $allow; do
    if [ "$host" = "$a" ]; then return 0; fi
  done
  return 1
}

clean_mods() {
  if [ "$CLEAN_MODS" != 1 ]; then
    echo "ModpackHub: keeping the mods already installed (replace existing mods was turned off)."
    return 0
  fi
  [ -d "$SERVER_DIR/mods" ] || return 0
  echo "ModpackHub: removing the mods of the previous pack..."
  find "$SERVER_DIR/mods" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
}

[ -n "$PACK_URL" ] || fail "PACK_URL missing"
case "$PACK_URL" in https://*) ;; *) fail "PACK_URL must be https" ;; esac

need curl curl
need unzip unzip

if [ "$PACK_FORMAT" = mrpack ] || [ "$PACK_FORMAT" = cfpack ]; then need jq jq; fi

rm -rf "$TMP"
mkdir -p "$TMP"

echo "ModpackHub: downloading the pack ($PACK_FORMAT)..."
curl "${CURL_SAFE[@]}" -o "$TMP/pack.bin" "$PACK_URL" || fail "pack download failed"

ZIP_LIST=""
read_zip_list() {
  ZIP_LIST="$(unzip -Z1 "$1")" || fail "unreadable archive (not a zip?)"
  if grep -Eq '^/|(^|/)\.\.(/|$)' <<< "$ZIP_LIST"; then
    fail "archive with path traversal: rejected"
  fi
}

case "$PACK_FORMAT" in
  zip)
    read_zip_list "$TMP/pack.bin"
    clean_mods
    unzip -qo "$TMP/pack.bin" -d "$SERVER_DIR"

    TOP="$(sed 's#/.*##' <<< "$ZIP_LIST" | sort -u | sed '/^$/d')"
    if [ "$(wc -l <<< "$TOP")" -eq 1 ] && [ -d "$SERVER_DIR/$TOP" ] && [ "$TOP" != "." ]; then
      echo "ModpackHub: flattening folder '$TOP'"
      shopt -s dotglob
      mv "$SERVER_DIR/$TOP"/* "$SERVER_DIR"/
      shopt -u dotglob
      rmdir "$SERVER_DIR/$TOP"
    fi
    ;;

  mrpack)
    read_zip_list "$TMP/pack.bin"
    unzip -qo "$TMP/pack.bin" -d "$TMP/mrpack"
    INDEX="$TMP/mrpack/modrinth.index.json"
    [ -f "$INDEX" ] || fail "mrpack without modrinth.index.json"

    if [ -z "$LOADER_VERSION" ] || [ "$LOADER" = none ]; then
      for dep in forge neoforge fabric-loader quilt-loader; do
        found="$(jq -r --arg k "$dep" '.dependencies[$k] // empty' "$INDEX")"
        [ -n "$found" ] || continue
        LOADER_VERSION="$found"
        LOADER="${dep%-loader}"
        break
      done
    fi
    [ -n "$MC_VERSION" ] || MC_VERSION="$(jq -r '.dependencies.minecraft // empty' "$INDEX")"
    echo "ModpackHub: mrpack for MC ${MC_VERSION:-?} loader ${LOADER}${LOADER_VERSION:+ $LOADER_VERSION}"

    clean_mods

    while IFS=$'\t' read -r path url; do
      [ -n "$path" ] || continue
      case "$path" in /*|*..*) fail "mrpack: unsafe path ($path)" ;; esac
      [ -n "$url" ] || fail "mrpack: no download for $path"

      url_allowed "$url" "$MR_HOSTS" || fail "mrpack: download from a host that is not allowed ($path)"
      mkdir -p "$SERVER_DIR/$(dirname "$path")"
      curl "${CURL_SAFE[@]}" -o "$SERVER_DIR/$path" "$url" || fail "download failed: $path"
    done < <(jq -r '.files[] | select((.env.server // "required") != "unsupported") | [.path, .downloads[0]] | @tsv' "$INDEX")

    for dir in overrides server-overrides; do
      [ -d "$TMP/mrpack/$dir" ] || continue
      echo "ModpackHub: copying $dir/"
      cp -a "$TMP/mrpack/$dir/." "$SERVER_DIR/"
    done
    ;;

  cfpack)

    read_zip_list "$TMP/pack.bin"
    unzip -qo "$TMP/pack.bin" -d "$TMP/cfpack"
    MANIFEST="$TMP/cfpack/manifest.json"
    [ -f "$MANIFEST" ] || fail "curseforge pack without manifest.json"

    [ -f "$FILES_LIST" ] || fail "the mod list from the panel is missing: the installation cannot continue"

    [ -n "$MC_VERSION" ] || MC_VERSION="$(jq -r '.minecraft.version // empty' "$MANIFEST")"
    ML="$(jq -r '.minecraft.modLoaders[]? | select(.primary != false) | .id // empty' "$MANIFEST" | head -1)"
    case "$ML" in
      fabric-*)   ML_NAME=fabric;   ML_VER="${ML#fabric-}" ;;
      neoforge-*) ML_NAME=neoforge; ML_VER="${ML#neoforge-}" ;;
      forge-*)    ML_NAME=forge;    ML_VER="${ML#forge-}" ;;
      quilt-*)    ML_NAME=quilt;    ML_VER="${ML#quilt-}" ;;
      *)          ML_NAME=""; ML_VER="" ;;
    esac
    if [ "$LOADER" = none ] && [ -n "$ML_NAME" ]; then LOADER="$ML_NAME"; fi
    if [ -z "$LOADER_VERSION" ]; then LOADER_VERSION="$ML_VER"; fi
    echo "ModpackHub: curseforge pack for MC ${MC_VERSION:-?} loader ${LOADER}${LOADER_VERSION:+ $LOADER_VERSION}"

    if [ "$INSTALL_LOADER" != 1 ] && [ -n "$ML_NAME" ]; then
      echo "ModpackHub: WARNING - this pack does not bundle the $ML_NAME loader and 'install the mod loader' was off:" >&2
      echo "ModpackHub: the mods will be installed but the server will need the loader to start." >&2
    fi

    clean_mods
    mkdir -p "$SERVER_DIR/mods"

    COUNT=0
    while IFS=$'\t' read -r path url; do
      [ -n "$path" ] || continue
      case "$path" in /*|*..*) fail "unsafe path in the mod list ($path)" ;; esac
      [ -n "$url" ] || fail "no download url for $path"
      url_allowed "$url" "$CF_HOSTS" || fail "mod download from a host that is not allowed: $path"
      mkdir -p "$SERVER_DIR/$(dirname "$path")"
      curl "${CURL_SAFE[@]}" -o "$SERVER_DIR/$path" "$url" || fail "download failed: $path"
      COUNT=$((COUNT + 1))
    done < "$FILES_LIST"
    [ "$COUNT" -gt 0 ] || fail "the mod list from the panel was empty"
    echo "ModpackHub: $COUNT mods downloaded"

    if [ -d "$TMP/cfpack/overrides" ]; then
      echo "ModpackHub: merging overrides/ into the server root"
      cp -a "$TMP/cfpack/overrides/." "$SERVER_DIR/"
    fi
    ;;

  *)
    fail "PACK_FORMAT '$PACK_FORMAT' not supported (zip|mrpack|cfpack)"
    ;;
esac

if [ "$INSTALL_LOADER" = 1 ] && [ "$LOADER" != none ]; then
  need java default-jre-headless
  [ -n "$LOADER_VERSION" ] || fail "INSTALL_LOADER=1 but LOADER_VERSION is empty"

  case "$LOADER" in
    forge)    LOADER_JAR="https://maven.minecraftforge.net/net/minecraftforge/forge/${MC_VERSION}-${LOADER_VERSION}/forge-${MC_VERSION}-${LOADER_VERSION}-installer.jar" ;;
    neoforge) LOADER_JAR="https://maven.neoforged.net/releases/net/neoforged/neoforge/${LOADER_VERSION}/neoforge-${LOADER_VERSION}-installer.jar" ;;
    fabric)   LOADER_JAR="https://meta.fabricmc.net/v2/versions/loader/${MC_VERSION}/${LOADER_VERSION}/1.0.1/server/jar" ;;
    *)        fail "automatic installer not available for loader '$LOADER'" ;;
  esac

  echo "ModpackHub: installing $LOADER $LOADER_VERSION..."
  curl "${CURL_SAFE[@]}" -o "$TMP/loader.jar" "$LOADER_JAR" || fail "download of the $LOADER installer failed"

  if [ "$LOADER" = fabric ]; then
    mv "$TMP/loader.jar" "$SERVER_DIR/server.jar"
  else
    (cd "$SERVER_DIR" && java -jar "$TMP/loader.jar" --installServer) || fail "$LOADER installer failed"
  fi
fi

if [ "$ACCEPT_EULA" = 1 ]; then
  echo "eula=true" > "$SERVER_DIR/eula.txt"
else
  echo "ModpackHub: EULA not accepted, eula.txt not written."
fi

rm -rf "$TMP"

rm -f "$FILES_LIST"

RESULT_WRITTEN=1
printf 'ok\n' > "$RESULT"
echo "ModpackHub: installation completed."
