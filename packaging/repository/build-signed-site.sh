#!/bin/sh
# Turn one fresh native build into signed OPNsense 26.7 test and stable repos.
# Run only in a disposable FreeBSD CI VM. Never put a signing key in Git.
set -eu

[ "$#" -eq 2 ] || { echo "Usage: $0 PACKAGE_DIR SITE_DIR" >&2; exit 2; }
PKG_DIR=$(CDPATH= cd -- "$1" && pwd)
mkdir -p "$2"
SITE=$(CDPATH= cd -- "$2" && pwd)
: "${OS_XRAY_REPO_KEY_B64:?Set the private Actions secret OS_XRAY_REPO_KEY_B64}"
command -v pkg >/dev/null
command -v openssl >/dev/null
command -v base64 >/dev/null

umask 077
SECURE_DIR=$(mktemp -d /tmp/os-xray-sign.XXXXXX)
trap 'rm -rf "$SECURE_DIR"' EXIT HUP INT TERM
KEY="$SECURE_DIR/repo.key"
printf '%s' "$OS_XRAY_REPO_KEY_B64" | base64 -d > "$KEY"
chmod 0600 "$KEY"
openssl pkey -in "$KEY" -noout >/dev/null 2>&1 || {
    echo "Invalid repository signing key" >&2; exit 1;
}

REPO="$SITE/FreeBSD:15:amd64/26.7/test/repo"
STABLE_REPO="$SITE/FreeBSD:15:amd64/26.7/stable/repo"
mkdir -p "$REPO" "$STABLE_REPO" "$SITE/Keys"
[ -f "$PKG_DIR/os-xray-3.3.0_1.pkg" ] || { echo "Missing os-xray-3.3.0_1.pkg" >&2; exit 1; }
[ -f "$PKG_DIR/xray-tun2socks-2.7.0.pkg" ] || { echo "Missing xray-tun2socks-2.7.0.pkg" >&2; exit 1; }
# Both channels receive exactly the same freshly built artifacts.
for dir in "$REPO" "$STABLE_REPO"; do
    cp "$PKG_DIR/os-xray-3.3.0_1.pkg" "$dir/"
    cp "$PKG_DIR/xray-tun2socks-2.7.0.pkg" "$dir/"
done
openssl pkey -in "$KEY" -pubout -out "$SITE/Keys/repo.pub" 2>/dev/null

# pkg signs the repository catalogue. No repository signature_type:none.
pkg repo "$REPO" "rsa:$KEY"
pkg repo "$STABLE_REPO" "rsa:$KEY"

# Verify signed catalogue with a clean, isolated pkg client configuration.
mkdir -p "$SECURE_DIR/repos" "$SECURE_DIR/pkgdb"
cat > "$SECURE_DIR/repos/os-xray-test.conf" <<EOF
os-xray-test: {
  url: "file://$REPO",
  signature_type: "pubkey",
  pubkey: "$SITE/Keys/repo.pub",
  enabled: yes
}
EOF
pkg -o REPOS_DIR="$SECURE_DIR/repos" -o PKG_DBDIR="$SECURE_DIR/pkgdb" update -f -r os-xray-test
pkg -o REPOS_DIR="$SECURE_DIR/repos" -o PKG_DBDIR="$SECURE_DIR/pkgdb" rquery -U -r os-xray-test '%n-%v' os-xray
pkg -o REPOS_DIR="$SECURE_DIR/repos" -o PKG_DBDIR="$SECURE_DIR/pkgdb" rquery -U -r os-xray-test '%n-%v' xray-tun2socks

# Independently verify the stable signed catalogue, not just copied .pkg files.
cat > "$SECURE_DIR/repos/os-xray.conf" <<EOF
os-xray: {
  url: "file://$STABLE_REPO",
  signature_type: "pubkey",
  pubkey: "$SITE/Keys/repo.pub",
  enabled: yes
}
EOF
pkg -o REPOS_DIR="$SECURE_DIR/repos" -o PKG_DBDIR="$SECURE_DIR/pkgdb" update -f -r os-xray
[ "$(pkg -o REPOS_DIR="$SECURE_DIR/repos" -o PKG_DBDIR="$SECURE_DIR/pkgdb" rquery -U -r os-xray '%n-%v' os-xray)" = 'os-xray-3.3.0_1' ]
[ "$(pkg -o REPOS_DIR="$SECURE_DIR/repos" -o PKG_DBDIR="$SECURE_DIR/pkgdb" rquery -U -r os-xray '%n-%v' xray-tun2socks)" = 'xray-tun2socks-2.7.0' ]

# HTTPS-only bootstrap configuration. Keep the official OPNsense repository enabled.
cat > "$SITE/os-xray-test.conf" <<'EOF'
os-xray-test: {
  url: "https://lfyz-git.github.io/os-xray/${ABI}/26.7/test/repo",
  signature_type: "pubkey",
  pubkey: "/usr/local/etc/pkg/keys/os-xray.pub",
  enabled: yes
}
EOF
cat > "$SITE/os-xray.conf" <<'EOF'
os-xray: {
  url: "https://lfyz-git.github.io/os-xray/${ABI}/26.7/stable/repo",
  signature_type: "pubkey",
  pubkey: "/usr/local/etc/pkg/keys/os-xray.pub",
  enabled: yes
}
EOF
cat > "$SITE/index.html" <<'EOF'
<!DOCTYPE html>
<html lang="en"><meta charset="utf-8">
<title>os-xray signed packages for OPNsense</title>
<h1>os-xray: signed native OPNsense packages</h1>
<p>OPNsense 26.7 / FreeBSD 15 amd64.</p>
<p>Release channel: <a href="os-xray.conf">os-xray.conf</a>. Test channel: <a href="os-xray-test.conf">os-xray-test.conf</a>.</p>
<p>Verify the public signing key independently before trusting the repository.</p>
<p>See <a href="https://github.com/Lfyz-git/os-xray/blob/main/packaging/repository/README.md">setup instructions</a>.</p>
</html>
EOF
touch "$SITE/.nojekyll"
(cd "$REPO" && sha256 *.pkg > SHA256SUMS)
(cd "$STABLE_REPO" && sha256 *.pkg > SHA256SUMS)
cmp "$REPO/os-xray-3.3.0_1.pkg" "$STABLE_REPO/os-xray-3.3.0_1.pkg"
cmp "$REPO/xray-tun2socks-2.7.0.pkg" "$STABLE_REPO/xray-tun2socks-2.7.0.pkg"
# Never publish private keys even if a future CI change accidentally stages one.
if find "$SITE" -type f \( -name '*.key' -o -name '*.pem' \) | grep -q .; then
    echo "Refusing to publish private key material" >&2; exit 1;
fi
echo "Signed test and stable repositories prepared. Public key SHA256:"
sha256 -q "$SITE/Keys/repo.pub"
