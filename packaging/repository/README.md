# os-xray test pkg repository — OPNsense 26.7 amd64

This is a **personal test repository** hosted on GitHub Pages of
`Lfyz-git/os-xray`. It contains exactly two packages:

- `os-xray-3.3.0_1.pkg` — native OPNsense plugin, first update after Lab validation.
- `xray-tun2socks-2.7.0.pkg` — separate, checksum-verified dependency.

`xray-core` and `php85-curl` remain dependencies from **OPNsense**,
not repackaged or overridden. The test repo uses the standard `pkg` metadata
format and a signed catalogue. No official OPNsense plugin listing or
support commitment is required.

## One-time administrator setup (GitHub)

1. Generate a **persistent RSA private key on your own trusted computer**
   and keep a secure offline copy. Do **not** commit it, upload it as an
   artifact, paste it into issues or share it in ChatGPT:

   ```sh
   clear
   umask 077
   openssl genrsa -out os-xray-repo.key 3072
   openssl pkey -in os-xray-repo.key -pubout -out os-xray-repo.pub
   openssl dgst -sha256 os-xray-repo.pub
   openssl base64 -A -in os-xray-repo.key
   ```

   Store the resulting single-line base64 value in the **repository's**
   Settings → Secrets and variables → Actions → New repository secret,
   with the exact name `OS_XRAY_REPO_KEY_B64`.
   The fingerprint output is your independent reference for verifying
   the downloaded public key on the Lab firewall.

2. In the same GitHub repository, open Settings → Pages and set the
   deployment source to **GitHub Actions**. This requires a one-time
   administrator UI action; committing the workflow does not enable Pages.

3. Actions → **Publish signed os-xray test repository** → Run workflow on
   `main`. The workflow pins the **same main commit** for both
   repository infrastructure and native-package source, then builds both
   native packages in an isolated FreeBSD 15.1 amd64 VM,
   signs the catalogue, verifies it using an isolated `pkg` client,
   uploads inspected artifacts and deploys to GitHub Pages. Private
   key material is decoded only into `/tmp` in the VM, then deleted.

The resulting public URL is:

`https://lfyz-git.github.io/os-xray/FreeBSD:15:amd64/26.7/test/repo`

The site is only available after GitHub Pages is enabled and the
manual publish workflow finishes successfully.

### Freshness / failure policy

Each manual publication **rebuilds** both private packages in a disposable
FreeBSD VM. Before building, CI clears cached package files and repository
databases, force-refreshes the official FreeBSD package catalogues, and fetches
current dependencies. Installed `xray-core` and `php85-curl` versions must
match the newly fetched FreeBSD catalogue; a failed refresh, missing package,
version mismatch, or download error **aborts publication**. There is no
fallback to previous GitHub Actions build artifacts, stale metadata, or local
package caches. Download timeouts keep a broken mirror from hanging for long.

This verifies the **FreeBSD build-time** dependency metadata, not the
availability of OPNsense's own firmware repository. The official OPNsense
dependency resolution must also be checked when installing on OPNsense-Lab.
The intentionally pinned, checksum-verified `tun2socks` 2.7.0 source is
separate from the no-stale-cache rule.

## On a freshly installed OPNsense-Lab only

This is **not** a migration from the old `install.sh` installation.
First ensure Lab has no manually installed copies of os-xray binaries,
MVC files or service hooks.

Download the **public** key and compare its checksum with your own
locally generated `os-xray-repo.pub`. Do not trust it solely because
it came from the same web server as the packages.

```sh
clear
opnsense-version -a
uname -m
sudo mkdir -p /usr/local/etc/pkg/keys /usr/local/etc/pkg/repos
sudo fetch -o /usr/local/etc/pkg/keys/os-xray.pub \
  https://lfyz-git.github.io/os-xray/Keys/repo.pub
sha256 /usr/local/etc/pkg/keys/os-xray.pub
sudo fetch -o /usr/local/etc/pkg/repos/os-xray-test.conf \
  https://lfyz-git.github.io/os-xray/os-xray-test.conf
sudo pkg update -f -r os-xray-test
pkg rquery -r os-xray-test '%n %v %R' os-xray
pkg rquery -r os-xray-test '%n %v %R' xray-tun2socks
```

Expected: `26.7`, `amd64`, a matching public-key SHA-256, and
exactly the two packages from `os-xray-test`. Stop if anything differs.

Then inspect System → Firmware → Plugins, enable **Show community plugins**,
find `os-xray` and install using the normal GUI. Observe dependency
resolution and installation logs. Do not enable it on Styx.

## Release boundaries

- The publication workflow runs manually, never on ordinary pushes.
- Only after the updated package passes OPNsense-Lab upgrade and runtime tests,
  tag `v3.3.0`. The tag workflow creates a native GitHub Release linking this
  signed repository; it does **not** publish an obsolete `install.sh` archive.
- No migrator, legacy upgrade code, or backwards-compatibility tests.
- The test channel is deliberately separate from a future stable channel.
- A subsequent package-only revision can verify Firmware GUI updates.
- Disable `os-xray-test` after experimenting if it is not needed.
- Do not delete the private signing key after initial publication: every
  future repository update must be signed with the same key, unless you
  deliberately rotate client trust.
